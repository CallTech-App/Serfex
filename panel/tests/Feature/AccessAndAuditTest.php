<?php

namespace Tests\Feature;

use App\Exceptions\ReadOnlyAccount;
use App\Livewire\Settings\AuditLogPage;
use App\Livewire\Sites\SiteCron;
use App\Livewire\Sites\SiteFtp;
use App\Models\AuditEntry;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Audit\AuditLog;
use App\Services\Customers\Customers;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Administrator roles (Salt okunur only looks) and İşlem günlüğü: every
 * toast a page shows is logged, by whom and where, with Undo from the log
 * while it can still be undone.
 */
class AccessAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'cron.apply' => fn ($p) => ['tasks' => count($p['tasks']), 'enabled' => count(array_filter($p['tasks'], fn ($t) => $t['enabled']))],
            'cron.remove' => ['removed' => true],
            'cron.runs' => ['runs' => [], 'running' => false],
            'ftp.status' => ['installed' => true, 'ready' => true, 'port' => 22, 'accounts' => 0],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr']);
    }

    private function readOnly(): User
    {
        return User::factory()->create(['name' => 'Deniz', 'role' => 'readonly']);
    }

    public function test_a_read_only_administrator_sees_the_pages_and_a_banner(): void
    {
        $this->actingAs($this->readOnly());
        foreach (['/', route('sites.index'), route('sites.show', $this->site), route('sites.cron', $this->site), route('sites.ftp', $this->site),
            route('dns.index'), route('customers.index'), route('ssl.index'), route('databases.index')] as $url) {
            $this->get($url)->assertOk()->assertSee('Salt okunur hesapla bakıyorsunuz');
        }
        $this->get('/')->assertDontSee(route('ai.index'))->assertSee('Salt okunur');
        $this->get(route('ai.index'))->assertForbidden();
        $this->get(route('settings.general'))->assertForbidden();
    }

    public function test_a_read_only_administrator_cannot_change_anything(): void
    {
        $customer = Customer::factory()->withLogin()->create();
        $this->actingAs($this->readOnly());

        Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->assertSet('editing', true)
            ->set('command', 'php cron.php')
            ->call('save')
            ->assertDispatched('toast', message: ReadOnlyAccount::MESSAGE);
        $this->assertSame(0, CronJob::count());
        $this->assertSame([], $this->agent->called('cron.apply'));

        // Whatever slips past the page: the agent and the database refuse.
        $this->assertSame(['runs' => [], 'running' => false], app(AgentClient::class)->call('cron.runs', ['domain' => 'kahve.com.tr', 'id' => 1]));
        $this->assertThrows(fn () => app(AgentClient::class)->call('cron.apply', []), ReadOnlyAccount::class);
        $this->assertThrows(fn () => Setting::put('ssl.auto', false), ReadOnlyAccount::class);

        // Plain form posts too.
        $this->post(route('customers.impersonate', $customer))->assertForbidden();
        // A hand-made X-Livewire header does not make a form post a Livewire call.
        $this->post(route('customers.impersonate', $customer), [], ['X-Livewire' => '1'])->assertForbidden();
        $this->assertNull(session(Impersonation::KEY));
        $this->post(route('logout'))->assertRedirect();
    }

    public function test_a_page_action_is_logged_with_section_and_undo_from_the_log(): void
    {
        $admin = User::factory()->create(['name' => 'Aylin']);
        $this->actingAs($admin);
        $page = '/sites/'.$this->site->id.'/cron-jobs';

        Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->set('command', 'php artisan rapor:gonder')
            ->call('save')
            ->call('confirmDelete', CronJob::sole()->id)
            ->call('delete');

        $entries = AuditEntry::orderBy('id')->get();
        $this->assertSame(['Zamanlanmış görev eklendi: Her 5 dakikada.', 'Zamanlanmış görev silindi.'], $entries->pluck('message')->all());
        $deleted = $entries[1];
        $this->assertSame('Aylin', $deleted->actor);
        $this->assertSame('Siteler · kahve.com.tr', $deleted->section);
        // Browsers send the page as Referer with Livewire updates; the test
        // client cannot, so the page is filled in here.
        $deleted->update(['url' => $page]);
        $this->assertSame(['method' => 'restore', 'args' => [CronJob::withTrashed()->sole()->id]], $deleted->undo);
        $this->assertTrue($deleted->undoable());

        $this->get(route('settings.audit'))->assertOk()
            ->assertSee('Zamanlanmış görev silindi.')
            ->assertSee($page.'?'.AuditLog::UNDO_PARAM.'='.$deleted->id, false);

        // The log's Undo opens the page, which undoes it and drops the link.
        $this->get($page.'?'.AuditLog::UNDO_PARAM.'='.$deleted->id)->assertRedirect($page);
        $this->assertNotNull(CronJob::sole());
        $this->assertNotNull($deleted->fresh()->undone_at);
        $this->assertSame('Zamanlanmış görev geri alındı.', AuditEntry::latest('id')->first()->message);

        // Once undone, the link does nothing more.
        $this->get($page.'?'.AuditLog::UNDO_PARAM.'='.$deleted->id)->assertRedirect($page);
        $this->assertSame(1, CronJob::count());
    }

    public function test_the_toasts_own_undo_marks_the_entry_undone(): void
    {
        $this->actingAs(User::factory()->create());
        CronJob::create(['site_id' => $this->site->id, 'schedule' => '0 3 * * *', 'command' => 'php yedek.php']);
        $id = CronJob::sole()->id;

        Livewire::test(SiteCron::class, ['site' => $this->site->id])
            ->call('pause', $id)
            ->call('resume', $id);

        $pause = AuditEntry::where('message', 'Görev duraklatıldı; zamanı gelince çalışmaz.')->sole();
        $this->assertNotNull($pause->undone_at);
        $this->assertFalse($pause->undoable());
    }

    public function test_undo_from_the_log_is_for_its_author_or_a_full_administrator_within_the_window(): void
    {
        $support = User::factory()->create(['name' => 'Emre', 'role' => 'support']);
        $other = User::factory()->create(['name' => 'Ayşe', 'role' => 'support']);
        $task = CronJob::create(['site_id' => $this->site->id, 'schedule' => '0 3 * * *', 'command' => 'php yedek.php']);
        $task->delete();
        $page = '/sites/'.$this->site->id.'/cron-jobs';
        $entry = AuditLog::record('Zamanlanmış görev silindi.', $support, extra: ['url' => $page, 'component' => SiteCron::class, 'undo' => ['method' => 'restore', 'args' => [$task->id]]]);

        $this->actingAs($other)->get($page.'?'.AuditLog::UNDO_PARAM.'='.$entry->id)->assertRedirect($page);
        $this->assertTrue($task->fresh()->trashed());

        $this->travel(AuditEntry::UNDO_MINUTES + 1)->minutes();
        $this->actingAs(User::factory()->create(['role' => 'full']))->get($page.'?'.AuditLog::UNDO_PARAM.'='.$entry->id)->assertRedirect($page);
        $this->assertTrue($task->fresh()->trashed());
        $this->travelBack();

        $this->actingAs(User::factory()->create(['role' => 'full']))->get($page.'?'.AuditLog::UNDO_PARAM.'='.$entry->id)->assertRedirect($page);
        $this->assertFalse($task->fresh()->trashed());
    }

    public function test_the_log_filters_by_person_and_text_and_records_sign_ins(): void
    {
        $admin = User::factory()->create(['name' => 'Aylin', 'email' => 'aylin@kahve.com.tr']);
        $this->post('/login', ['email' => 'aylin@kahve.com.tr', 'password' => 'password'])->assertRedirect();
        $this->assertSame('Panele giriş yaptı.', AuditEntry::sole()->message);
        $this->assertNotNull($admin->fresh()->last_login_at);

        AuditLog::record('DNS kaydı eklendi: www.kahve.com.tr', $admin, extra: ['section' => 'Alan Adları ve DNS']);
        AuditLog::record('Site oluşturuluyor.', $admin, 'api', ['actor' => 'API: Faturalama (Aylin)']);

        Livewire::test(AuditLogPage::class)
            ->assertSee('Panele giriş yaptı.')
            ->set('search', 'dns')
            ->assertSee('www.kahve.com.tr')
            ->assertDontSee('Panele giriş yaptı.')
            ->set('search', '')
            ->set('who', 'api')
            ->assertSee('API: Faturalama (Aylin)')
            ->assertDontSee('www.kahve.com.tr');
    }

    public function test_customers_actions_are_logged_with_their_customer(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);

        Livewire::test(SiteCron::class, ['site' => $site->id])
            ->call('openCreate')
            ->set('command', 'php cron.php')
            ->call('save');

        $entry = AuditEntry::sole();
        $this->assertSame($customer->id, $entry->customer_id);
        Livewire::test(SiteFtp::class, ['site' => $site->id])->assertOk();
        $this->get(route('settings.audit'))->assertForbidden();
    }
}
