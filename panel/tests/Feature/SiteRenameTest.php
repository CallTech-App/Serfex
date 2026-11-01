<?php

namespace Tests\Feature;

use App\Enums\SiteStatus;
use App\Jobs\DeleteCertificate;
use App\Jobs\IssueCertificate;
use App\Jobs\RenameSite;
use App\Livewire\Sites\SiteShow;
use App\Models\Certificate;
use App\Models\CronJob;
use App\Models\Customer;
use App\Models\DnsZone;
use App\Models\LaravelApp;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteRedirect;
use App\Models\SiteRename;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Sites\SiteRenames;
use App\Services\Ssl\DnsCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Mockery;
use Mockery\MockInterface;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Alan adını değiştir: a site moves to another domain with its files,
 * certificate, DNS, workers and tasks, and can move back.
 */
class SiteRenameTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    private User $admin;

    /** @var array<string, bool> name => resolves here */
    private array $pointing = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake([RenameSite::class, IssueCertificate::class, DeleteCertificate::class]);
        $this->agent = new FakeAgent([
            'site.apply' => fn ($p) => ['uid' => 1001, 'home' => '/home/odur', 'document_root' => '/home/odur/web/'.$p['domain'].'/public_html'],
            'site.rename' => fn ($p) => ['domain' => $p['site']['domain'], 'document_root' => '/home/odur/web/'.$p['site']['domain'].'/laravel/current/public', 'links' => 1,
                'notes' => [['code' => 'configs', 'subject' => 'laravel/shared/.env'], ['code' => 'config_cache', 'subject' => 'laravel/releases/3']], 'ms' => 800],
            'ssl.issue' => fn ($p) => ['name' => $p['name'], 'names' => $p['names'], 'issuer' => "Let's Encrypt R11", 'serial' => '04ab', 'fingerprint' => 'aa:bb', 'not_before' => '2026-11-01T10:00:00Z', 'not_after' => '2027-01-30T10:00:00Z'],
            'laravel.queue.apply' => [],
            'laravel.schedule.apply' => [],
            'laravel.process.apply' => [],
            'redis.apply' => ['unit' => 'serfex-redis', 'port' => 6390, 'changed' => true],
            'cron.apply' => [],
            'dns.zone.apply' => [],
            'mail.domain.apply' => [],
            'mail.domain.delete' => [],
            'backup.run.read' => ['data' => "▸ Alan adı değiştiriliyor\n", 'offset' => 30, 'size' => 30],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->app->instance(DnsCheck::class, Mockery::mock(DnsCheck::class, function (MockInterface $m) {
            $m->shouldReceive('check')->andReturnUsing(fn ($name) => ['ok' => $this->pointing[$name] ?? false, 'found' => ($this->pointing[$name] ?? false) ? ['203.0.113.20'] : ['198.51.100.7'], 'proxied' => false]);
            $m->shouldReceive('serverAddresses')->andReturn(['203.0.113.20']);
        }));
        Setting::put('dns.ipv4', '203.0.113.20');
        $this->site = Site::factory()->create(['domain' => 'updates.odur.net', 'aliases' => ['www.updates.odur.net'], 'provisioned_at' => now(), 'php_version' => '8.4', 'web_root' => 'laravel/current/public']);
        $this->site->systemUser->update(['name' => 'odur', 'home' => '/home/odur']);
        Certificate::factory()->inUseBy($this->site)->create();
        $this->admin = User::factory()->create();
        $this->actingAs($this->admin);
    }

    private function runRename(SiteRename $rename): SiteRename
    {
        app(SiteRenames::class)->run($rename);

        return $rename->fresh();
    }

    private function start(string $name, string $old = SiteRename::OLD_REDIRECT, bool $www = false): SiteRename
    {
        return app(SiteRenames::class)->start($this->site->fresh(), $name, $www, $old, false, $this->admin);
    }

    public function test_a_site_moves_to_a_subdomain_of_a_zone_here_without_losing_https(): void
    {
        $zone = DnsZone::factory()->create(['name' => 'roifex.com']);
        $this->pointing = ['updates.roifex.com' => true, 'updates.odur.net' => true, 'www.updates.odur.net' => true];
        $app = LaravelApp::factory()->create(['site_id' => $this->site->id, 'redis_port' => 6390, 'redis_password' => str_repeat('a', 40)]);
        $task = CronJob::create(['site_id' => $this->site->id, 'schedule' => '* * * * *', 'command' => 'php /home/odur/web/updates.odur.net/laravel/current/artisan odur:sync', 'enabled' => true]);
        $oldCertificate = $this->site->certificate_id;

        $rename = $this->start('updates.roifex.com');
        $this->assertSame(SiteStatus::Renaming, $this->site->fresh()->status);
        Queue::assertPushed(RenameSite::class);
        $rename = $this->runRename($rename);

        $this->assertSame('succeeded', $rename->status, (string) $rename->error);
        $site = $this->site->fresh();
        $this->assertSame('updates.roifex.com', $site->domain);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame([], $site->aliases, 'the old www. name went with the old name; the new one was not asked for');
        $this->assertSame('/home/odur/web/updates.roifex.com/laravel/current/public', $site->document_root);

        // The certificate of the new name came before the switch.
        $issued = $this->agent->called('ssl.issue')[0];
        $this->assertSame(['updates.roifex.com', 'updates.odur.net', 'www.updates.odur.net'], $issued['names']);
        $spec = $this->agent->called('site.rename')[0];
        $this->assertSame('updates.odur.net', $spec['from']);
        $this->assertSame('updates.roifex.com', $spec['site']['domain']);
        $this->assertSame('updates.roifex.com', $spec['site']['tls']['certificate']);
        $this->assertSame([['hosts' => ['updates.odur.net', 'www.updates.odur.net'], 'target' => 'https://updates.roifex.com', 'code' => 301, 'keep_path' => true]], $spec['site']['redirects']);
        $this->assertSame([['www.updates.odur.net', 'updates.roifex.com'], ['updates.odur.net', 'updates.roifex.com']], $spec['hosts']);
        $this->assertNotSame($oldCertificate, $site->certificate_id);
        Queue::assertPushed(DeleteCertificate::class, fn ($job) => $job->certificate->id === $oldCertificate);

        // The old name redirects; the task's path follows; DNS in roifex.com.
        $redirect = SiteRedirect::where('site_id', $site->id)->firstOrFail();
        $this->assertSame(['updates.odur.net', 'www.updates.odur.net'], $redirect->hosts());
        $this->assertSame('php /home/odur/web/updates.roifex.com/laravel/current/artisan odur:sync', $task->fresh()->command);
        $this->assertTrue($zone->records()->where('name', 'updates')->where('type', 'A')->where('content', '203.0.113.20')->exists());

        // Workers, Redis and tasks start again under the new name.
        $this->assertSame('updates.roifex.com', $this->agent->called('redis.apply')[0]['domain']);
        $this->assertSame(6390, $this->agent->called('redis.apply')[0]['port']);
        $this->assertSame('updates.roifex.com', $this->agent->called('laravel.queue.apply')[0]['domain']);
        $this->assertSame('updates.roifex.com', $this->agent->called('cron.apply')[0]['domain']);
        $this->assertSame('ok', $app->fresh()->processes_state);
        $this->assertEqualsCanonicalizing(['configs', 'config_cache', 'certificate_issued', 'dns_records'], array_values(array_intersect(array_column($rename->notes, 'code'), ['configs', 'config_cache', 'certificate_issued', 'dns_records'])));
        $this->assertNotNull($rename->undoUntil());
    }

    public function test_a_new_name_not_pointing_here_keeps_the_old_name_on_https(): void
    {
        $this->pointing = ['updates.odur.net' => true];
        $oldCertificate = $this->site->certificate_id;

        $rename = $this->runRename($this->start('odurupdates.com', SiteRename::OLD_ALIAS, www: true));

        $this->assertSame('succeeded', $rename->status, (string) $rename->error);
        $site = $this->site->fresh();
        $this->assertSame('odurupdates.com', $site->domain);
        $this->assertSame(['www.odurupdates.com', 'updates.odur.net', 'www.updates.odur.net'], $site->aliases);
        // Old clients keep reaching the old name over HTTPS until the new
        // certificate comes.
        $this->assertSame($oldCertificate, $site->certificate_id);
        $this->assertCount(0, $this->agent->called('ssl.issue'));
        $pending = Certificate::where('site_id', $site->id)->whereKeyNot($oldCertificate)->firstOrFail();
        $this->assertContains('odurupdates.com', $pending->domains);
        Queue::assertPushed(IssueCertificate::class);
        $this->assertContains('certificate_later', array_column($rename->notes, 'code'));
        // An external domain gets its own zone here, like a new site.
        $this->assertTrue(DnsZone::where('name', 'odurupdates.com')->where('site_id', $site->id)->exists());
        $this->assertSame([['www.updates.odur.net', 'www.odurupdates.com'], ['updates.odur.net', 'odurupdates.com']], $this->agent->called('site.rename')[0]['hosts']);
    }

    public function test_a_failed_rename_leaves_the_site_on_its_old_name(): void
    {
        LaravelApp::factory()->create(['site_id' => $this->site->id]);
        $this->agent->answers['site.rename'] = function () {
            $e = new AgentException('Agent call [site.rename] failed', AgentException::CONFIG_REJECTED);
            $e->reason = 'nginx rejected the configuration: [emerg] broken';
            throw $e;
        };

        $rename = $this->runRename($this->start('updates.roifex.com'));

        $this->assertSame('failed', $rename->status);
        $this->assertStringContainsString('[emerg] broken', $rename->error);
        $site = $this->site->fresh();
        $this->assertSame('updates.odur.net', $site->domain);
        $this->assertSame(SiteStatus::Active, $site->status);
        $this->assertSame(['www.updates.odur.net'], $site->aliases);
        $this->assertSame(0, SiteRedirect::count());
        // What was stopped runs again under the old name.
        $this->assertSame('updates.odur.net', $this->agent->called('laravel.queue.apply')[0]['domain']);
    }

    public function test_names_that_cannot_be_taken_are_refused_and_an_alias_can_become_the_name(): void
    {
        $renames = app(SiteRenames::class);
        Site::factory()->create(['domain' => 'taken.com']);
        $this->assertStringContainsString('zaten bir site', (string) $renames->nameRefusal($this->site, 'taken.com', $this->admin));
        $this->assertNotNull($renames->nameRefusal($this->site, 'updates.odur.net', $this->admin));
        $this->assertNotNull($renames->nameRefusal($this->site, 'not a domain', $this->admin));

        // One of its own additional domains: the swap.
        $this->site->update(['aliases' => ['www.updates.odur.net', 'odur.net']]);
        $this->assertNull($renames->nameRefusal($this->site->fresh(), 'odur.net', $this->admin));
        $rename = $this->runRename($this->start('odur.net', SiteRename::OLD_ALIAS));
        $this->assertSame('succeeded', $rename->status, (string) $rename->error);
        $this->assertSame(['updates.odur.net', 'www.updates.odur.net'], $this->site->fresh()->aliases);

        // A customer cannot take a name in another account's zone.
        $customer = Customer::factory()->create();
        $owned = Site::factory()->create(['domain' => 'shop.com.tr']);
        $owned->systemUser->update(['customer_id' => $customer->id]);
        DnsZone::factory()->create(['name' => 'roifex.com', 'site_id' => Site::factory()->create(['domain' => 'roifex.com'])->id]);
        $user = User::factory()->create(['is_admin' => false, 'customer_id' => $customer->id]);
        $this->assertNotNull($renames->nameRefusal($owned, 'shop.roifex.com', $user));
    }

    public function test_a_rename_is_undone_back_to_the_old_name(): void
    {
        $this->pointing = ['updates.roifex.com' => true, 'updates.odur.net' => true, 'www.updates.odur.net' => true];
        $first = $this->runRename($this->start('updates.roifex.com'));
        $this->assertSame('succeeded', $first->status, (string) $first->error);

        $undo = app(SiteRenames::class)->undo($first, $this->admin);
        $this->assertInstanceOf(SiteRename::class, $undo);
        $undo = $this->runRename($undo);

        $this->assertSame('succeeded', $undo->status, (string) $undo->error);
        $site = $this->site->fresh();
        $this->assertSame('updates.odur.net', $site->domain);
        $this->assertSame(['www.updates.odur.net'], $site->aliases);
        $this->assertSame(0, SiteRedirect::count(), 'the redirect made for the old name went');
        $this->assertSame('undone', $first->fresh()->status);
        $this->assertNull($undo->undoUntil(), 'an undo is not undone in turn');
        // The old name's certificate (kept for the undo) serves again.
        $this->assertTrue(Certificate::find($site->certificate_id)->domains === ['updates.odur.net', 'www.updates.odur.net'] || in_array('updates.odur.net', Certificate::find($site->certificate_id)->domains, true));
    }

    public function test_the_dialog_shows_what_will_happen_and_queues_the_rename(): void
    {
        DnsZone::factory()->create(['name' => 'roifex.com']);
        $this->pointing = ['updates.roifex.com' => true];

        Livewire::test(SiteShow::class, ['site' => $this->site->id])
            ->call('openRename')
            ->assertSet('renaming', true)
            ->assertSet('renameWww', true)
            ->set('newDomain', ' Updates.Roifex.com ')
            ->assertSet('newDomain', 'updates.roifex.com')
            ->assertSet('renameWww', false)
            ->assertSee('Neler olacak')
            ->assertSee('~/web/updates.roifex.com')
            ->assertSee('roifex.com')
            ->assertSee('değişiklikten önce alınır')
            ->set('renameOld', SiteRename::OLD_ALIAS)
            ->set('renameBackup', false)
            ->call('rename')
            ->assertHasNoErrors()
            ->assertSet('renaming', false);

        $rename = SiteRename::firstOrFail();
        $this->assertSame(['www' => false, 'old' => 'alias', 'backup' => false], $rename->options);
        Queue::assertPushed(RenameSite::class);
        $this->assertSame(SiteStatus::Renaming, $this->site->fresh()->status);

        Livewire::test(SiteShow::class, ['site' => $this->site->id])
            ->assertSee('Alan adı değiştiriliyor')
            ->call('openRename')
            ->assertSet('renaming', false);
    }

    public function test_a_taken_name_shows_why_in_the_dialog(): void
    {
        Site::factory()->create(['domain' => 'taken.com']);
        Livewire::test(SiteShow::class, ['site' => $this->site->id])
            ->call('openRename')
            ->set('newDomain', 'taken.com')
            ->assertSee('taken.com zaten bir site.')
            ->assertDontSee('Neler olacak')
            ->call('rename')
            ->assertHasErrors('newDomain');
        $this->assertSame(0, SiteRename::count());
    }
}
