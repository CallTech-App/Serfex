<?php

namespace Tests\Feature;

use App\Livewire\Sites\SiteShow;
use App\Livewire\Sites\SiteTerminal;
use App\Models\AuditEntry;
use App\Models\Customer;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Files\SshAccess;
use App\Services\Files\WebTerminal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * SSH terminali: full administrators open the site user's shell in the
 * browser, only when the account has a shell; the agent hands out a
 * one-time ticket and the opening and the end go into the audit log.
 */
class WebTerminalTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    /** @var array<string, array<string, mixed>> the agent's sessions */
    private array $sessions = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'terminal.open' => function ($p) {
                $id = 'sess'.(count($this->sessions) + 1);
                $this->sessions[$id] = ['id' => $id, 'user' => $p['user'], 'site' => $p['site'], 'actor' => $p['actor'], 'ip' => $p['ip'], 'state' => 'waiting', 'created' => now()->toIso8601String()];

                return ['id' => $id, 'token' => str_repeat('a', 64), 'path' => '/terminal/socket', 'expires' => now()->addMinute()->toIso8601String(), 'idle_seconds' => 900, 'max_seconds' => 7200];
            },
            'terminal.list' => fn () => array_values($this->sessions),
            'terminal.get' => fn ($p) => $this->sessions[$p['id']],
            'terminal.close' => function ($p) {
                $this->end($p['id'], 'closed');

                return $this->sessions[$p['id']];
            },
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr', 'document_root' => '/home/kahve/web/kahveatolyesi.com.tr/public_html']);
        $this->site->systemUser->update(['name' => 'kahve', 'home' => '/home/kahve', 'ssh_access' => SshAccess::SHELL]);
        $this->actingAs(User::factory()->create(['name' => 'Sercan']));
    }

    private function end(string $id, string $reason): void
    {
        $this->sessions[$id] = ['state' => 'ended', 'reason' => $reason, 'started' => now()->subMinutes(12)->toIso8601String(), 'ended' => now()->toIso8601String()] + $this->sessions[$id];
    }

    public function test_opens_a_ticket_for_the_site_user_and_logs_it(): void
    {
        $this->get(route('sites.terminal', $this->site))->assertOk()->assertSee('Terminali aç')->assertSee('kahve');

        Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->call('start');
        $calls = $this->agent->called('terminal.open');
        $this->assertCount(1, $calls);
        $this->assertSame('kahve', $calls[0]['user']);
        $this->assertSame('/home/kahve/web/kahveatolyesi.com.tr/public_html', $calls[0]['dir']);
        $this->assertSame('kahveatolyesi.com.tr', $calls[0]['site']);
        $this->assertSame('Sercan', $calls[0]['actor']);
        $this->assertSame(WebTerminal::IDLE_MINUTES, $calls[0]['idle_minutes']);
        $this->assertSame(WebTerminal::MAX_MINUTES, $calls[0]['max_minutes']);
        $this->assertDatabaseHas('audit_entries', ['message' => 'SSH terminali açıldı: kahve kullanıcısı (kahveatolyesi.com.tr).', 'section' => 'Siteler']);

        // The page reports the close: logged once, with the reason.
        $this->end('sess1', 'idle');
        Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->call('ended', 'sess1')->assertReturned('15 dakika işlem yapılmadı');
        Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->call('ended', 'sess1');
        $this->assertSame(1, AuditEntry::where('message', 'like', 'SSH terminali kapandı:%')->count());
        $this->assertDatabaseHas('audit_entries', ['message' => 'SSH terminali kapandı: kahve kullanıcısı (kahveatolyesi.com.tr), 12 dk. 15 dakika işlem yapılmadı.']);
    }

    public function test_start_returns_the_ticket(): void
    {
        $component = Livewire::test(SiteTerminal::class, ['site' => $this->site->id]);
        $ticket = $component->instance()->start(app(WebTerminal::class));
        $this->assertSame('/terminal/socket', $ticket['path']);
        $this->assertSame(64, strlen($ticket['token']));
    }

    public function test_refuses_without_a_shell_or_while_suspended(): void
    {
        $this->site->systemUser->update(['ssh_access' => SshAccess::SFTP]);
        $this->get(route('sites.terminal', $this->site))->assertOk()->assertSee('kabuk erişimi kapalı')->assertSee('SSH erişimini aç')->assertDontSee('Terminali aç');
        $ticket = Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->instance()->start(app(WebTerminal::class));
        $this->assertArrayHasKey('error', $ticket);
        $this->assertSame([], $this->agent->called('terminal.open'));

        $customer = Customer::factory()->create(['suspended_at' => now()]);
        $this->site->systemUser->update(['ssh_access' => SshAccess::SHELL, 'customer_id' => $customer->id]);
        $ticket = Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->instance()->start(app(WebTerminal::class));
        $this->assertStringContainsString('askıda', $ticket['error']);
        $this->assertSame([], $this->agent->called('terminal.open'));
    }

    public function test_only_full_administrators(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'support']));
        $this->get(route('sites.terminal', $this->site))->assertForbidden();

        $customer = Customer::factory()->create();
        $this->site->systemUser->update(['customer_id' => $customer->id]);
        $this->actingAs(User::factory()->member()->create(['customer_id' => $customer->id]));
        $this->get(route('sites.terminal', $this->site))->assertForbidden();
    }

    public function test_the_agents_limit_is_explained(): void
    {
        $this->agent->answers['terminal.open'] = AgentException::rpcError('terminal.open', WebTerminal::LIMIT_CODE, 'too many terminal sessions are open');
        $ticket = Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->instance()->start(app(WebTerminal::class));
        $this->assertSame('Sunucuda aynı anda en fazla 5 terminal açık olabilir. Açık olanlardan birini kapatın.', $ticket['error']);
    }

    public function test_open_sessions_are_listed_and_can_be_closed(): void
    {
        app(WebTerminal::class)->open(auth()->user(), $this->site, '203.0.113.5');
        $this->sessions['sess1']['state'] = 'open';
        $this->sessions['sess1']['started'] = now()->toIso8601String();
        $this->sessions['other'] = ['id' => 'other', 'user' => 'someone', 'state' => 'open', 'actor' => 'X', 'ip' => '', 'created' => now()->toIso8601String()];

        Livewire::test(SiteTerminal::class, ['site' => $this->site->id])
            ->assertSee('Açık oturumlar')->assertSee('203.0.113.5')
            ->call('endSession', 'sess1')
            ->assertDispatched('toast');
        $this->assertSame([['id' => 'sess1']], $this->agent->called('terminal.close'));

        // Another account's session is not this page's to close.
        Livewire::test(SiteTerminal::class, ['site' => $this->site->id])->call('endSession', 'other');
        $this->assertCount(1, $this->agent->called('terminal.close'));

        // The scheduler logs the end the page never reported.
        $this->artisan('serfex:terminal-sessions')->assertSuccessful();
        $this->assertDatabaseHas('audit_entries', ['message' => 'SSH terminali kapandı: kahve kullanıcısı (kahveatolyesi.com.tr), 12 dk. Oturum kapatıldı.']);
    }

    public function test_site_page_links_the_terminal(): void
    {
        Livewire::test(SiteShow::class, ['site' => $this->site->id])
            ->assertSee(route('sites.terminal', $this->site))->assertSee('Tarayıcıda kabuk')->assertDontSee('Yakında');
        $this->site->systemUser->update(['ssh_access' => SshAccess::OFF]);
        Livewire::test(SiteShow::class, ['site' => $this->site->id])->assertSee('Kabuk erişimi kapalı');
    }
}
