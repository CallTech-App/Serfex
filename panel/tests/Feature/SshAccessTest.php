<?php

namespace Tests\Feature;

use App\Jobs\DeleteSite;
use App\Jobs\SyncSshAccess;
use App\Livewire\Sites\SiteShow;
use App\Livewire\Sites\SiteSsh;
use App\Models\Customer;
use App\Models\Package;
use App\Models\Site;
use App\Models\SshKey;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Dns\Zones;
use App\Services\Files\SshAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * SSH erişimi: a site's own Linux account signs in over SFTP (locked in
 * its home) or, when an administrator allows it, a shell; with the keys
 * the panel keeps and an optional password shown once. Suspension locks
 * it, deleting the account removes it, and the package decides whether
 * customers get it.
 */
class SshAccessTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'ftp.status' => ['installed' => true, 'ready' => true, 'port' => 22, 'accounts' => 0],
            'ssh.user.apply' => fn ($p) => ['user' => $p['user'], 'access' => $p['locked'] ? 'off' : $p['access'], 'folder' => '/home/'.$p['user'], 'keys' => count($p['keys']), 'port' => 22],
            'site.delete' => ['removed_user' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create());
    }

    /** An ed25519 public key line, as ssh-keygen writes it. */
    private static function ed25519(string $comment = 'sercan@laptop'): string
    {
        $pk = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

        return 'ssh-ed25519 '.base64_encode(self::str('ssh-ed25519').self::str($pk)).' '.$comment;
    }

    private static function rsa(int $bits): string
    {
        $n = "\0".chr(0xC5).random_bytes($bits / 8 - 1);

        return 'ssh-rsa '.base64_encode(self::str('ssh-rsa').self::str("\x01\x00\x01").self::str($n));
    }

    private static function str(string $s): string
    {
        return pack('N', strlen($s)).$s;
    }

    private function last(): array
    {
        $calls = $this->agent->called('ssh.user.apply');

        return end($calls) ?: [];
    }

    public function test_the_site_page_links_ssh_access(): void
    {
        $this->get(route('sites.show', $this->site))->assertOk()
            ->assertSee(route('sites.ssh', $this->site))
            ->assertSee('SSH erişimi');
        $this->get(route('sites.ssh', $this->site))->assertOk()
            ->assertSee('kahve')
            ->assertSee('Henüz anahtar yok.');
    }

    public function test_turning_sftp_on_and_undoing_it(): void
    {
        $page = Livewire::test(SiteSsh::class, ['site' => $this->site->id])
            ->set('access', SshAccess::SFTP)
            ->call('saveAccess')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'undoAccess', 'args' => [SshAccess::OFF]])
            ->assertSee('sftp kahve@kahveatolyesi.com.tr');
        $this->assertSame(['user' => 'kahve', 'access' => 'sftp', 'keys' => [], 'password_login' => false, 'locked' => false], $this->last());
        $this->assertSame(SshAccess::SFTP, $this->site->systemUser->fresh()->ssh_access);

        $page->call('undoAccess', SshAccess::OFF)->assertSet('access', SshAccess::OFF);
        $this->assertSame('off', $this->last()['access']);
        $this->assertSame(SshAccess::OFF, $this->site->systemUser->fresh()->ssh_access);
    }

    public function test_a_refusal_keeps_the_old_access(): void
    {
        $this->agent->answers['ssh.user.apply'] = AgentException::rpcError('ssh.user.apply', -32602, 'kahve is not a site user');
        Livewire::test(SiteSsh::class, ['site' => $this->site->id])
            ->set('access', SshAccess::SHELL)
            ->call('saveAccess')
            ->assertSet('access', SshAccess::OFF)
            ->assertDispatched('toast');
        $this->assertSame(SshAccess::OFF, $this->site->systemUser->fresh()->ssh_access);
    }

    public function test_keys_are_checked_added_and_deleted_with_undo(): void
    {
        $key = self::ed25519();
        $page = Livewire::test(SiteSsh::class, ['site' => $this->site->id])
            ->call('openAdd')
            ->set('publicKey', "  {$key}\n")
            ->call('addKey')
            ->assertHasNoErrors()
            ->assertSet('adding', false);
        $saved = SshKey::sole();
        $this->assertSame('sercan@laptop', $saved->name);
        $this->assertSame('ED25519', $saved->typeLabel());
        [$type, $blob] = explode(' ', $key);
        $this->assertSame('SHA256:'.rtrim(base64_encode(hash('sha256', base64_decode($blob), true)), '='), $saved->fingerprint);
        $this->assertSame([$type.' '.$blob], $this->last()['keys']);

        foreach ([
            '-----BEGIN OPENSSH PRIVATE KEY-----' => 'özel anahtarı',
            'ssh-ed25519 bm90IGEga2V5' => 'bozuk',
            self::rsa(1024) => '2048',
            $key => 'zaten ekli',
        ] as $line => $error) {
            $page->call('openAdd')->set('publicKey', $line)->call('addKey')
                ->assertHasErrors('publicKey')
                ->assertSee($error);
        }
        $page->call('openAdd')->set('keyName', 'Sunucu')->set('publicKey', self::rsa(4096))->call('addKey')->assertHasNoErrors();
        $this->assertCount(2, $this->last()['keys']);

        $page->call('confirmDelete', $saved->id)
            ->call('deleteKey')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo'] ?? null) === ['method' => 'restoreKey', 'args' => [$saved->id]]);
        $this->assertCount(1, $this->last()['keys']);
        $page->call('restoreKey', $saved->id);
        $this->assertCount(2, $this->last()['keys']);
    }

    public function test_a_password_is_shown_once_and_password_logins_can_be_turned_off(): void
    {
        $this->site->systemUser->update(['ssh_access' => SshAccess::SFTP]);
        $page = Livewire::test(SiteSsh::class, ['site' => $this->site->id])->call('newPassword');
        $password = $this->last()['password'];
        $this->assertSame(20, strlen($password));
        $this->assertTrue($this->last()['password_login']);
        $page->assertSet('credentials.Parola', $password);
        $this->assertNotNull($at = $this->site->systemUser->fresh()->ssh_password_at);

        $page->set('credentials', null)
            ->call('removePassword')
            ->assertDispatched('toast', fn ($name, $p) => ($p['undo']['method'] ?? null) === 'restorePassword');
        $this->assertFalse($this->last()['password_login']);
        $this->assertArrayNotHasKey('password', $this->last());
        $this->assertNull($this->site->systemUser->fresh()->ssh_password_at);

        $page->call('restorePassword', $at->toIso8601String());
        $this->assertTrue($this->last()['password_login']);
        $this->assertArrayNotHasKey('password', $this->last());
    }

    public function test_customers_get_sftp_within_their_package_but_never_a_shell(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);

        $customer->package->update(['allow_ssh' => false]);
        $this->get(route('sites.show', $mine))->assertOk()->assertDontSee(route('sites.ssh', $mine));
        $this->get(route('sites.ssh', $mine))->assertForbidden();

        $customer->package->update(['allow_ssh' => true]);
        $this->actingAs($customer->user->fresh());
        $this->get(route('sites.ssh', $this->site))->assertNotFound();
        $page = Livewire::test(SiteSsh::class, ['site' => $mine->id])
            ->set('access', SshAccess::SHELL)
            ->call('saveAccess')
            ->assertSet('access', SshAccess::OFF);
        $this->assertSame([], $this->agent->called('ssh.user.apply'));

        $page->set('access', SshAccess::SFTP)->call('saveAccess');
        $this->assertSame(['user' => $mine->systemUser->name, 'access' => 'sftp'], array_intersect_key($this->last(), ['user' => 0, 'access' => 0]));

        // Someone else's key is out of reach by id.
        $theirs = $this->site->systemUser->sshKeys()->create(['name' => 'x', 'public_key' => 'ssh-ed25519 AAAA', 'fingerprint' => 'SHA256:x']);
        $page->call('confirmDelete', $theirs->id)->assertNotFound();
    }

    public function test_an_administrators_shell_stays_for_the_customer_to_keep_or_turn_off(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $mine->systemUser->update(['ssh_access' => SshAccess::SHELL]);
        $this->actingAs($customer->user);

        Livewire::test(SiteSsh::class, ['site' => $mine->id])
            ->assertSee('ssh '.$mine->systemUser->name.'@adapansiyon.com')
            ->set('access', SshAccess::OFF)
            ->call('saveAccess')
            ->call('undoAccess', SshAccess::SHELL);
        $this->assertSame(SshAccess::OFF, $mine->systemUser->fresh()->ssh_access);
    }

    public function test_suspending_the_customer_locks_ssh(): void
    {
        $customer = Customer::factory()->create(['username' => 'ada']);
        $site = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $site->systemUser->update(['ssh_access' => SshAccess::SFTP, 'ssh_password_at' => now()]);

        app(Customers::class)->suspend($customer, 'Ödeme gecikti');
        Queue::assertPushed(SyncSshAccess::class, fn ($job) => $job->systemUserId === $site->system_user_id);
        (new SyncSshAccess($site->system_user_id))->handle(app(SshAccess::class));
        $this->assertTrue($this->last()['locked']);
        $this->assertTrue($this->last()['password_login']);

        app(Customers::class)->unsuspend($customer->fresh());
        (new SyncSshAccess($site->system_user_id))->handle(app(SshAccess::class));
        $this->assertFalse($this->last()['locked']);
    }

    public function test_deleting_the_last_site_with_its_account_turns_ssh_off_first(): void
    {
        $this->site->systemUser->update(['ssh_access' => SshAccess::SFTP]);

        (new DeleteSite($this->site, removeUser: true))->handle($this->agent, app(Zones::class));

        $methods = array_values(array_filter($this->agent->methods(), fn ($m) => in_array($m, ['ssh.user.apply', 'site.delete'], true)));
        $this->assertSame(['ssh.user.apply', 'site.delete'], $methods);
        $this->assertSame('off', $this->agent->called('ssh.user.apply')[0]['access']);
    }

    public function test_presets_give_ssh_to_the_bigger_packages(): void
    {
        $this->assertFalse(Package::where('name', 'Başlangıç')->sole()->allows('ssh'));
        $this->assertTrue(Package::where('name', 'Profesyonel')->sole()->allows('ssh'));
        $this->assertTrue(Package::where('name', 'Kurumsal')->sole()->allows('ssh'));
    }

    public function test_site_show_still_renders_for_a_shell_account(): void
    {
        $this->site->systemUser->update(['ssh_access' => SshAccess::SHELL]);
        Livewire::test(SiteShow::class, ['site' => $this->site->id])->assertSee('SFTP ve kabuk');
    }
}
