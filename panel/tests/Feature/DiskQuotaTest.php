<?php

namespace Tests\Feature;

use App\Enums\MailDomainStatus;
use App\Jobs\EnableDiskQuotas;
use App\Jobs\SyncMailDomain;
use App\Livewire\Customers\CustomerShow;
use App\Livewire\Settings\ServerSettings;
use App\Models\Customer;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Customers\DiskQuotas;
use App\Services\Customers\Quotas;
use App\Services\Files\FileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Customers' disk limits enforced with file system quotas: limits follow
 * usage, a full customer cannot write and their mail waits, and an
 * administrator turns quotas on, keeps them as warnings, or gives one
 * customer their own limit.
 */
class DiskQuotaTest extends TestCase
{
    use RefreshDatabase;

    private const MB = 1024 * 1024;

    private FakeAgent $agent;

    /** @var array<string, array{bytes: int, limit: int}> what repquota reports */
    private array $fs = [];

    private array $status = ['state' => 'on', 'mount' => '/', 'device' => '/dev/vda1', 'fstype' => 'ext4', 'enabled_at' => '2026-10-28T09:00:00Z'];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['serfex.disk_paths' => []]);
        $this->agent = new FakeAgent([
            'quota.status' => fn () => $this->status,
            'quota.report' => fn ($p) => array_intersect_key($this->fs, array_flip($p['users'])),
            'quota.set' => function ($p) {
                foreach ($p['limits'] as $user => $bytes) {
                    $this->fs[$user]['limit'] = $bytes;
                }

                return ['set' => count($p['limits']), 'missing' => []];
            },
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * A customer on a 1 GB package with one account (kahve) that has a
     * site with mail.
     *
     * @return array{Customer, SystemUser, MailDomain}
     */
    private function customer(int $diskMb = 1024): array
    {
        $package = Package::factory()->create(['max_disk_mb' => $diskMb]);
        $customer = Customer::factory()->create(['package_id' => $package->id, 'username' => 'kahve']);
        $account = app(Customers::class)->account($customer);
        $account->update(['uid' => 1001]);
        $site = Site::factory()->for($account)->create(['domain' => 'kahve.com.tr']);
        $mail = MailDomain::factory()->create(['name' => 'kahve.com.tr', 'site_id' => $site->id, 'status' => MailDomainStatus::Active]);

        return [$customer, $account, $mail];
    }

    public function test_an_account_may_fill_what_mail_and_databases_leave_of_the_limit(): void
    {
        [$customer, $account, $mail] = $this->customer();
        Mailbox::factory()->create(['mail_domain_id' => $mail->id, 'usage_bytes' => 300 * self::MB]);
        $this->fs['kahve'] = ['bytes' => 500 * self::MB, 'limit' => 0];

        app(DiskQuotas::class)->sync();

        // 1024 MB package - 300 MB mail = 724 MB for files.
        $this->assertSame([['limits' => ['kahve' => 724 * self::MB]]], $this->agent->called('quota.set'));
        $this->assertSame(500 * self::MB, $account->fresh()->disk_bytes, 'usage comes from the file system');
        $this->assertSame(724 * self::MB, $account->fresh()->quota_limit_bytes);
        $this->assertNull($customer->fresh()->disk_full_at);
        Queue::assertNotPushed(SyncMailDomain::class);
        $this->assertFalse($mail->fresh()->agentSpec()['disk_full']);

        // Nothing changed: nothing is sent again.
        app(DiskQuotas::class)->sync();
        $this->assertCount(1, $this->agent->called('quota.set'));
    }

    public function test_a_full_customer_is_held_where_they_are_and_their_mail_waits(): void
    {
        [$customer, $account, $mail] = $this->customer();
        Mailbox::factory()->create(['mail_domain_id' => $mail->id, 'usage_bytes' => 300 * self::MB]);
        $this->fs['kahve'] = ['bytes' => 724 * self::MB, 'limit' => 724 * self::MB];

        app(DiskQuotas::class)->sync();

        $this->assertNotNull($customer->fresh()->disk_full_at);
        // The limit already equals what the account holds.
        $this->assertSame([], $this->agent->called('quota.set'));
        Queue::assertPushed(SyncMailDomain::class, fn ($job) => $job->domain->is($mail));
        $this->assertSame(MailDomainStatus::Syncing, $mail->fresh()->status);
        $this->assertTrue($mail->fresh()->agentSpec()['disk_full']);
        $this->assertSame('crit', app(Quotas::class)->report($customer->fresh())['disk']['level']);

        // Mail grew past the limit: files stay held where they are.
        Mailbox::factory()->create(['mail_domain_id' => $mail->id, 'usage_bytes' => 100 * self::MB]);
        app(DiskQuotas::class)->sync();
        $this->assertSame([], $this->agent->called('quota.set'));

        // An account with nothing in it is still held: 1 byte, never "no limit".
        $this->fs['kahve'] = ['bytes' => 0, 'limit' => 724 * self::MB];
        Mailbox::query()->update(['usage_bytes' => 1100 * self::MB]);
        app(DiskQuotas::class)->sync();
        $this->assertSame(['kahve' => 1], $this->agent->called('quota.set')[0]['limits']);

        // Room made: mail is taken again.
        Queue::fake();
        Mailbox::query()->update(['usage_bytes' => 0]);
        app(DiskQuotas::class)->sync();
        $this->assertNull($customer->fresh()->disk_full_at);
        Queue::assertPushed(SyncMailDomain::class);
        $this->assertSame(['kahve' => 1024 * self::MB], $this->agent->called('quota.set')[1]['limits']);
    }

    public function test_accounts_without_a_customer_or_with_an_unlimited_disk_have_no_limit(): void
    {
        SystemUser::factory()->create(['name' => 'yonetici', 'uid' => 1005]);
        [$customer] = $this->customer();
        $customer->update(['disk_override' => true, 'disk_override_mb' => null]);
        $this->fs = ['yonetici' => ['bytes' => 5 * self::MB, 'limit' => 10 * self::MB], 'kahve' => ['bytes' => 9000 * self::MB, 'limit' => 1024 * self::MB]];

        app(DiskQuotas::class)->sync();

        $this->assertSame([['limits' => ['yonetici' => 0, 'kahve' => 0]]], $this->agent->called('quota.set'));
        $this->assertNull($customer->fresh()->disk_full_at);
        $this->assertNull($customer->fresh()->diskLimit());
    }

    public function test_warnings_only_lifts_the_limits_and_takes_mail_again(): void
    {
        [$customer, $account] = $this->customer();
        $this->fs['kahve'] = ['bytes' => 2000 * self::MB, 'limit' => 0];
        $quotas = app(DiskQuotas::class);
        $quotas->sync();
        $this->assertNotNull($customer->fresh()->disk_full_at);
        $this->assertTrue($quotas->enforcing());

        Queue::fake();
        $quotas->setWanted(false);

        $this->assertFalse($quotas->enforcing());
        $this->assertSame(['kahve' => 0], last($this->agent->called('quota.set'))['limits']);
        $this->assertNull($account->fresh()->quota_limit_bytes);
        $this->assertNull($customer->fresh()->disk_full_at);
        Queue::assertPushed(SyncMailDomain::class);
        $this->assertSame([], $quotas->fullCustomers()->all());
    }

    public function test_a_server_without_quotas_keeps_the_limit_a_warning(): void
    {
        [$customer] = $this->customer();
        $this->status = ['state' => 'unsupported', 'reason' => 'fstype', 'fstype' => 'btrfs', 'mount' => '/', 'device' => '/dev/vda1'];
        $this->fs['kahve'] = ['bytes' => 2000 * self::MB, 'limit' => 0];

        $this->artisan('serfex:disk-quota')->assertSuccessful();

        $this->assertSame(['quota.status'], $this->agent->methods());
        $this->assertNull($customer->fresh()->disk_full_at);
        $this->assertFalse(app(DiskQuotas::class)->enforcing());
    }

    public function test_quotas_that_went_off_are_turned_on_again(): void
    {
        $this->customer();
        $this->status = ['state' => 'off', 'reason' => 'not_enabled', 'mount' => '/', 'fstype' => 'ext4', 'enabled_at' => '2026-10-28T09:00:00Z'];
        $this->agent->answers['quota.enable'] = function () {
            $this->status = ['state' => 'on', 'mount' => '/', 'fstype' => 'ext4', 'enabled_at' => '2026-10-28T09:00:00Z'];

            return $this->status;
        };
        $this->fs['kahve'] = ['bytes' => 10 * self::MB, 'limit' => 0];
        Setting::put(DiskQuotas::STATUS, $this->status);
        $this->assertTrue(app(DiskQuotas::class)->broken());

        $this->artisan('serfex:disk-quota')->assertSuccessful();

        $this->assertCount(1, $this->agent->called('quota.enable'));
        $this->assertSame(['kahve' => 1024 * self::MB], $this->agent->called('quota.set')[0]['limits']);
        $this->assertFalse(app(DiskQuotas::class)->broken());
    }

    public function test_an_administrator_gives_one_customer_their_own_limit(): void
    {
        [$customer] = $this->customer();
        $this->fs['kahve'] = ['bytes' => 100 * self::MB, 'limit' => 0];
        Setting::put(DiskQuotas::STATUS, $this->status);
        $this->actingAs(User::factory()->create(['role' => 'full']));

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->set('tab', 'details')
            ->assertSee('Paketteki (1 GB)')
            ->set('diskMode', 'custom')
            ->set('diskGb', '')
            ->call('saveInfo')
            ->assertHasErrors('diskGb')
            ->set('diskGb', '2,5')
            ->call('saveInfo')
            ->assertHasNoErrors();

        $customer->refresh();
        $this->assertTrue($customer->disk_override);
        $this->assertSame(2560, $customer->disk_override_mb);
        $this->assertSame(2560 * self::MB, $customer->diskLimit());
        $this->assertSame(['kahve' => 2560 * self::MB], $this->agent->called('quota.set')[0]['limits']);

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->assertSet('diskMode', 'custom')->assertSet('diskGb', '2.5')
            ->set('diskMode', 'package')->call('saveInfo')->assertHasNoErrors();
        $this->assertFalse($customer->fresh()->disk_override);
        $this->assertSame(1024 * self::MB, $customer->fresh()->diskLimit());
    }

    public function test_the_customer_page_and_usage_show_a_full_disk(): void
    {
        [$customer] = $this->customer();
        $this->fs['kahve'] = ['bytes' => 2000 * self::MB, 'limit' => 0];
        app(DiskQuotas::class)->sync();
        $this->actingAs(User::factory()->create(['role' => 'full']));

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->assertSee('Bu müşterinin disk kotası')
            ->assertSee('Gelen e-postalar bekletiliyor')
            ->assertSee('Disk dolu')
            ->assertSee('Disk sınırı dosya sisteminde uygulanıyor');
        $this->get(route('customers.usage', $customer))->assertOk()->assertSee('dosyalara yazılamaz');

        // The customer sees it on their own usage page.
        $user = User::factory()->create(['customer_id' => $customer->id, 'is_admin' => false]);
        $this->actingAs($user)->get(route('usage'))->assertOk()->assertSee('Disk alanınız')->assertSee('barındırma sağlayıcınıza');

        // And the dashboard lists it among the problems.
        $this->actingAs(User::factory()->create(['role' => 'full']))->get('/')->assertOk()
            ->assertSee(e($customer->displayName()).' müşterisinin disk kotası doldu', false)
            ->assertSee('Müşteriye git');
    }

    public function test_where_quotas_are_off_the_customer_page_says_warning_only(): void
    {
        [$customer] = $this->customer();
        Setting::put(DiskQuotas::STATUS, ['state' => 'off', 'reason' => 'not_enabled']);
        $this->actingAs(User::factory()->create(['role' => 'full']));

        Livewire::test(CustomerShow::class, ['customer' => $customer->id])
            ->assertSee('Bu sunucuda disk sınırı yalnızca uyarıdır')
            ->assertDontSee('Bu müşterinin disk kotası');
    }

    public function test_server_settings_turn_quotas_on_in_the_background(): void
    {
        $this->status = ['state' => 'off', 'reason' => 'not_enabled', 'mount' => '/', 'device' => '/dev/vda1', 'fstype' => 'ext4'];
        $this->agent->answers += [
            'system.info' => ['hostname' => 'srv', 'addresses' => ['ipv4' => [], 'ipv6' => []]],
            'logs.rotation.get' => ['keep_days' => 14],
        ];
        $this->actingAs(User::factory()->create(['role' => 'full']));

        Livewire::test(ServerSettings::class)
            ->assertSee('Disk kotası')->assertSee('Kapalı')->assertSee('Disk kotalarını aç')
            ->call('openQuota')->assertSet('confirmingQuota', true)
            ->assertSee('/etc/fstab.before-serfex-quota')
            ->call('enableQuota')
            ->assertSet('confirmingQuota', false)
            ->assertSee('Açılıyor');
        Queue::assertPushed(EnableDiskQuotas::class);
        $this->assertTrue(app(DiskQuotas::class)->enabling());

        // The job: the agent turns them on.
        $this->agent->answers['quota.enable'] = function () {
            $this->status = ['state' => 'on', 'mount' => '/', 'device' => '/dev/vda1', 'fstype' => 'ext4', 'enabled_at' => '2026-10-28T12:00:00Z'];

            return $this->status;
        };
        (new EnableDiskQuotas)->handle(app(DiskQuotas::class));
        $this->assertFalse(app(DiskQuotas::class)->enabling());
        $this->assertTrue(app(DiskQuotas::class)->enforcing());

        Livewire::test(ServerSettings::class)
            ->assertSee('Uygulanıyor')->assertSee('/ · ext4 · /dev/vda1')
            ->call('enforceQuota', false)
            ->assertSee('Yalnızca uyarı');
        $this->assertFalse(app(DiskQuotas::class)->enforcing());
    }

    public function test_a_failed_attempt_is_shown_and_an_unsupported_server_explains_why(): void
    {
        $this->status = ['state' => 'off', 'reason' => 'not_enabled', 'mount' => '/', 'fstype' => 'ext4'];
        $this->agent->answers += [
            'system.info' => ['hostname' => 'srv', 'addresses' => ['ipv4' => [], 'ipv6' => []]],
            'logs.rotation.get' => ['keep_days' => 14],
            'quota.enable' => $this->agentError('remounting / with quotas failed', AgentException::OPERATION_FAILED),
        ];
        $this->actingAs(User::factory()->create(['role' => 'full']));

        (new EnableDiskQuotas)->handle(app(DiskQuotas::class));
        $this->assertFalse(app(DiskQuotas::class)->enabling());
        Livewire::test(ServerSettings::class)->assertSee('Son deneme başarısız oldu: remounting / with quotas failed');

        $this->status = ['state' => 'unsupported', 'reason' => 'kernel', 'mount' => '/', 'fstype' => 'ext4'];
        Livewire::test(ServerSettings::class)
            ->assertSee('Bu sunucuda kullanılamıyor')
            ->assertSee('quota_v2')
            ->assertSee('Yeniden dene');
    }

    public function test_the_file_manager_names_a_full_quota(): void
    {
        $message = FileManager::message($this->agentError('quota: /home/kahve/web/x: disk quota exceeded', FileManager::ERROR));

        $this->assertStringContainsString('Disk kotası doldu', $message);
    }

    private function agentError(string $reason, int $code): AgentException
    {
        $e = new AgentException($reason, $code);
        $e->reason = $reason;

        return $e;
    }
}
