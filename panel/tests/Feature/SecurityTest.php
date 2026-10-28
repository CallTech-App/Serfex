<?php

namespace Tests\Feature;

use App\Jobs\RunSecurityScan;
use App\Livewire\Account\AccountSecurity;
use App\Livewire\Monitor\LiveMonitor;
use App\Livewire\Security\SecurityIndex;
use App\Livewire\Sites\SiteProtect;
use App\Livewire\Sites\SiteScan;
use App\Livewire\Sites\SiteWaf;
use App\Models\AiConversation;
use App\Models\Customer;
use App\Models\IpBlock;
use App\Models\ProtectedDirectory;
use App\Models\SecurityFinding;
use App\Models\SecurityScan;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Models\WafExclusion;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Ai\Tools;
use App\Services\Customers\Customers;
use App\Services\Security\Fail2ban;
use App\Services\Security\Firewall;
use App\Services\Security\Scans;
use App\Services\Security\TwoFactor;
use App\Services\Setup\FirstRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik: the port firewall that never locks the administrator out, the
 * WAF per site with rule exclusions, the security scan and its fixes
 * (each with Undo), password-protected folders, SSH, two-step login and
 * Fail2ban's bans in the IP block list.
 */
class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private Site $site;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'component.list' => ['components' => [
                ['id' => 'modsecurity', 'installed' => true, 'settings' => ['mode' => 'detect', 'paranoia' => 1]],
                ['id' => 'fail2ban', 'installed' => true],
            ]],
            'waf.events' => ['installed' => true, 'events' => [[
                'id' => 'e1', 'time' => '2026-10-19T12:00:00Z', 'ip' => '203.0.113.9', 'host' => 'kahveatolyesi.com.tr', 'method' => 'GET',
                'uri' => '/?id=1%20union%20select', 'status' => 403, 'blocked' => true, 'score' => 10,
                'rules' => [['id' => '942100', 'message' => 'SQL Injection Attack Detected via libinjection', 'severity' => 'CRITICAL', 'data' => '']],
                'user_agent' => 'sqlmap',
            ]]],
            'firewall.rules' => ['enabled' => false, 'rules' => [], 'active' => false, 'listening' => [
                ['protocol' => 'tcp', 'port' => 80, 'address' => '0.0.0.0', 'process' => 'nginx'],
                ['protocol' => 'tcp', 'port' => 6379, 'address' => '0.0.0.0', 'process' => 'redis-server'],
                ['protocol' => 'tcp', 'port' => 5433, 'address' => '127.0.0.1', 'process' => 'postgres'],
            ]],
            'ssh.status' => ['installed' => true, 'root_login' => 'yes', 'password_auth' => true, 'ports' => [22], 'managed' => false, 'key_accounts' => ['root']],
            'fail2ban.status' => ['installed' => true, 'running' => true, 'jails' => []],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        Setting::put('components.installed', ['fail2ban', 'modsecurity']);
        $this->site = Site::factory()->create(['domain' => 'kahveatolyesi.com.tr']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->admin = User::factory()->create(['name' => 'Aylin']);
        $this->actingAs($this->admin);
    }

    private function finding(array $data = []): SecurityFinding
    {
        $data += ['site_id' => $this->site->id, 'check' => 'webshell', 'code' => 'eval-decoded', 'severity' => 'critical',
            'path' => 'public_html/wp-content/uploads/x.php', 'fixes' => ['quarantine']];
        $scan = SecurityScan::create(['scope' => 'server', 'trigger' => 'manual', 'status' => 'succeeded', 'finished_at' => now()]);

        return SecurityFinding::create($data + ['scan_id' => $scan->id,
            'fingerprint' => Scans::fingerprint($data['site_id'], $data['check'], $data['code'], $data['path'])]);
    }

    // --- Güvenlik duvarı ---

    public function test_the_firewall_turns_on_with_the_ready_rules_and_never_locks_the_admin_out(): void
    {
        $this->get('/guvenlik')->assertOk()
            ->assertSee('Güvenlik duvarı')
            ->assertSee('Serfex paneli')
            ->assertSee('6379');

        Livewire::test(SecurityIndex::class)
            ->call('setFirewall', true)
            ->assertDispatched('toast', fn ($n, $p) => ($p['undo']['method'] ?? null) === 'restoreFirewall' && $p['undo']['args'][0]['enabled'] === false);
        $apply = $this->agent->called('firewall.rules.apply')[0];
        $this->assertTrue($apply['enabled']);
        $this->assertSame(['svc-panel', 'svc-web', 'svc-ssh'], array_column($apply['rules'], 'id'));
        $this->assertSame('127.0.0.1', $apply['protect']['address']);
        $this->assertTrue(app(Firewall::class)->enabled());

        // A rule that would cut the admin's own connection is refused and nothing is saved.
        $this->agent->answers['firewall.rules.apply'] = AgentException::rpcError('firewall.rules.apply', AgentException::INVALID_PARAMS, 'these rules would cut your own connection to the panel');
        Livewire::test(SecurityIndex::class)
            ->call('openRule', null)
            ->set('rule.name', 'Ofis')
            ->set('rule.ports', '8443')
            ->set('rule.sources', '198.51.100.0/24')
            ->set('rule.action', 'deny')
            ->call('saveRule')
            ->assertHasErrors('rule.name')
            ->assertSee('panele şu anki bağlantınızı keserdi');
        $this->assertSame([], app(Firewall::class)->settings()['rules']);
    }

    public function test_own_rules_are_checked_saved_deleted_and_restored(): void
    {
        $page = Livewire::test(SecurityIndex::class)
            ->call('openRule', null)
            ->set('rule.name', 'Node uygulaması')
            ->set('rule.ports', '3000-')
            ->call('saveRule')
            ->assertHasErrors('rule.ports')
            ->set('rule.ports', '3000, 3001')
            ->set('rule.sources', 'not-an-ip')
            ->call('saveRule')
            ->assertHasErrors('rule.sources')
            ->set('rule.sources', '198.51.100.7')
            ->call('saveRule')
            ->assertHasNoErrors()
            ->assertSet('editingRule', false);
        $rule = app(Firewall::class)->settings()['rules'][0];
        $this->assertSame(['3000, 3001', ['198.51.100.7'], 'allow'], [$rule['ports'], $rule['sources'], $rule['action']]);
        $this->assertMatchesRegularExpression('/^r-[a-z0-9]{10}$/', $rule['id']);

        $before = app(Firewall::class)->settings();
        $page->call('deleteRule', $rule['id'])
            ->assertDispatched('toast', fn ($n, $p) => $p['message'] === 'Node uygulaması silindi.' && $p['undo']['args'][0] === $before);
        $this->assertSame([], app(Firewall::class)->settings()['rules']);
        $page->call('restoreFirewall', $before);
        $this->assertSame($rule['id'], app(Firewall::class)->settings()['rules'][0]['id']);

        // The panel's own rule cannot be turned off; SSH can.
        $page->call('toggleService', 'panel')->call('toggleService', 'ssh');
        $services = app(Firewall::class)->services();
        $this->assertTrue($services['panel']['enabled']);
        $this->assertFalse($services['ssh']['enabled']);
    }

    // --- WAF ---

    public function test_a_site_has_its_own_waf_mode_with_undo(): void
    {
        $this->get('/sites/'.$this->site->id.'/waf')->assertOk()
            ->assertSee('203.0.113.9')
            ->assertSee('942100');

        Livewire::test(SiteWaf::class, ['site' => $this->site->id])
            ->set('mode', 'block')
            ->call('saveMode')
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'undoMode', 'args' => ['']])
            ->call('undoMode', '');
        $this->assertSame([
            ['domain' => 'kahveatolyesi.com.tr', 'mode' => 'block', 'exclusions' => []],
            ['domain' => 'kahveatolyesi.com.tr', 'mode' => '', 'exclusions' => []],
        ], $this->agent->called('waf.site.apply'));
        $this->assertNull($this->site->fresh()->waf_mode);

        // Without ModSecurity the mode stays as it was.
        $this->agent->answers['waf.site.apply'] = AgentException::rpcError('waf.site.apply', -32009, 'modsecurity is not installed');
        Livewire::test(SiteWaf::class, ['site' => $this->site->id])
            ->set('mode', 'off')
            ->call('saveMode')
            ->assertHasErrors('mode')
            ->assertSee('ModSecurity kurulu değil');
        $this->assertNull($this->site->fresh()->waf_mode);
    }

    public function test_rule_exclusions_are_checked_and_removed_with_undo(): void
    {
        $page = Livewire::test(SiteWaf::class, ['site' => $this->site->id, 'prefillRule' => '942100', 'prefillPath' => '/wp-admin/'])
            ->assertSet('excluding', true)
            ->assertSet('ruleId', '942100')
            ->set('path', 'yanlis yol')
            ->call('saveExclusion')
            ->assertHasErrors('path')
            ->set('path', '/wp-admin/')
            ->call('saveExclusion')
            ->assertHasNoErrors();
        $exclusion = WafExclusion::sole();
        $this->assertSame([['rule_id' => 942100, 'path' => '/wp-admin/']], $this->agent->called('waf.site.apply')[0]['exclusions']);

        $page->call('openExclude', 942100, '/wp-admin/')->call('saveExclusion')->assertHasErrors('ruleId');

        $page->call('removeExclusion', $exclusion->id)
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'restoreExclusion', 'args' => [$exclusion->id]]);
        $this->assertSame(0, WafExclusion::count());
        $page->call('restoreExclusion', $exclusion->id);
        $this->assertSame(1, WafExclusion::count());
        $this->assertCount(3, $this->agent->called('waf.site.apply'));
    }

    public function test_the_server_waf_mode_and_blocking_an_attacker(): void
    {
        $this->get('/guvenlik?sekme=waf')->assertOk()->assertSee('203.0.113.9')->assertSee('942100');

        $this->agent->answers['firewall.block'] = fn ($p) => ['address' => $p['address']];
        Livewire::test(SecurityIndex::class, ['tab' => 'waf'])
            ->call('openWafSettings')
            ->assertSet('wafMode', 'detect')
            ->set('wafMode', 'block')
            ->set('wafParanoia', 2)
            ->call('saveWafSettings')
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'undoWafSettings', 'args' => ['detect', 1]])
            ->call('openBlock', '203.0.113.9', $this->site->id, 'SQL injection')
            ->call('saveBlock')
            ->assertHasNoErrors();
        $this->assertSame(['id' => 'modsecurity', 'settings' => ['modsecurity_mode' => 'block', 'modsecurity_paranoia' => 2]], $this->agent->called('component.configure')[0]);
        $this->assertSame('waf', IpBlock::sole()->kind);
        $this->assertSame(86400, $this->agent->called('firewall.block')[0]['seconds']);
    }

    // --- Tarama ---

    public function test_a_scan_records_findings_and_keeps_them_across_scans(): void
    {
        $this->agent->answers['scan.run'] = ['clamav' => true, 'sites' => [['domain' => 'kahveatolyesi.com.tr', 'files' => 812, 'findings' => [
            ['check' => 'webshell', 'code' => 'eval-decoded', 'severity' => 'critical', 'path' => 'public_html/wp-content/uploads/x.php', 'fixes' => ['quarantine']],
            ['check' => 'exposed', 'code' => 'env', 'severity' => 'high', 'path' => 'public_html/.env', 'url' => '/.env', 'fixes' => ['deny', 'quarantine']],
        ]]]];

        $scan = app(Scans::class)->start(null, 'manual', $this->admin);
        Queue::assertPushed(RunSecurityScan::class);
        $this->assertSame('Sunucu taraması sürüyor; bitince sonuçlar burada.', app(Scans::class)->start($this->site));
        app(Scans::class)->run($scan);

        $scan->refresh();
        $this->assertSame(['succeeded', 812, true], [$scan->status, $scan->files, $scan->clamav]);
        $this->assertSame(2, SecurityFinding::where('site_id', $this->site->id)->open()->count());
        // Two in the site and SSH's root login with a password.
        $this->assertSame(3, Scans::attentionCount());
        // The server's own settings: no 2FA admin, firewall off, SSH root with password.
        $this->assertEqualsCanonicalizing(['no-2fa', 'firewall-off', 'ssh-root-password'], SecurityFinding::whereNull('site_id')->pluck('code')->all());

        // A second scan: the web shell is gone, the .env is still there.
        $ignored = SecurityFinding::where('code', 'env')->sole();
        app(Scans::class)->ignore($ignored, $this->admin);
        $this->agent->answers['scan.run']['sites'][0]['findings'] = [$this->agent->answers['scan.run']['sites'][0]['findings'][1]];
        app(Scans::class)->run(SecurityScan::create(['scope' => 'server', 'trigger' => 'schedule']));
        $this->assertSame('gone', SecurityFinding::where('code', 'eval-decoded')->sole()->status);
        $this->assertSame('ignored', $ignored->fresh()->status);
        $this->assertSame(2, SecurityFinding::where('site_id', $this->site->id)->count());

        $this->get('/guvenlik?sekme=tarama')->assertOk()->assertSee('root kullanıcısı SSH ile parolayla girebiliyor');
        $this->get('/guvenlik?sekme=tarama&durum=ignored')->assertOk()->assertSee('public_html/.env');
        $this->get('/')->assertOk()->assertSee('ciddi güvenlik bulgusu', false);
    }

    public function test_fixes_quarantine_and_deny_with_undo(): void
    {
        $shell = $this->finding();
        $env = $this->finding(['check' => 'exposed', 'code' => 'env', 'severity' => 'high', 'path' => 'public_html/.env', 'url' => '/.env', 'fixes' => ['deny', 'quarantine']]);
        $this->agent->answers['scan.fix'] = ['changed' => 1, 'trash_id' => 'q1'];
        $this->agent->answers['files.trash.restore'] = ['restored' => [['id' => 'q1', 'path' => 'public_html/wp-content/uploads/x.php']]];

        $page = Livewire::test(SiteScan::class, ['site' => $this->site->id])
            ->assertSee('x.php')
            ->call('fixFinding', $shell->id, 'quarantine')
            ->assertDispatched('toast', fn ($n, $p) => str_contains($p['message'], '30 gün') && $p['undo'] === ['method' => 'undoFinding', 'args' => [$shell->id]]);
        $this->assertSame(['fixed', 'quarantine'], [$shell->fresh()->status, $shell->fresh()->fix_action]);
        $this->assertSame('quarantine', $this->agent->called('scan.fix')[0]['action']);

        $page->call('undoFinding', $shell->id);
        $this->assertSame('open', $shell->fresh()->status);
        $this->assertSame(['q1'], $this->agent->called('files.trash.restore')[0]['ids']);

        // An action the finding does not offer does nothing.
        $page->call('fixFinding', $shell->id, 'deny');
        $this->assertSame('open', $shell->fresh()->status);

        $page->call('fixFinding', $env->id, 'deny');
        $this->assertSame(['/.env'], $this->site->fresh()->denied_paths);
        $this->assertSame(['domain' => 'kahveatolyesi.com.tr', 'paths' => ['/.env']], $this->agent->called('web.deny.apply')[0]);
        $page->call('undoFinding', $env->id);
        $this->assertNull($this->site->fresh()->denied_paths);

        $page->call('ignoreFinding', $shell->id)
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'reopenFinding', 'args' => [$shell->id]]);
        $this->assertSame('ignored', $shell->fresh()->status);
        $page->call('reopenFinding', $shell->id);
        $this->assertSame('open', $shell->fresh()->status);
    }

    public function test_blocked_paths_on_the_site_scan_page(): void
    {
        Livewire::test(SiteScan::class, ['site' => $this->site->id, 'tab' => 'engelli'])
            ->call('openDeny')
            ->set('denyPath', '/')
            ->call('saveDeny')
            ->assertHasErrors('denyPath')
            ->set('denyPath', 'yedek.sql')
            ->call('saveDeny')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'allow', 'args' => ['/yedek.sql']])
            ->assertSee('/yedek.sql')
            ->call('allow', '/yedek.sql')
            ->assertDispatched('toast', fn ($n, $p) => ($p['undo'] ?? null) === ['method' => 'deny', 'args' => ['/yedek.sql']]);
        $this->assertNull($this->site->fresh()->denied_paths);
    }

    public function test_uploads_are_scanned_at_once(): void
    {
        $this->agent->answers['scan.files'] = ['clamav' => false, 'findings' => [
            ['check' => 'webshell', 'code' => 'known-shell', 'severity' => 'critical', 'path' => 'public_html/c99.php', 'fixes' => ['quarantine']],
        ]];
        $rows = app(Scans::class)->scanUploads($this->site, ['public_html/c99.php']);
        $this->assertCount(1, $rows);
        $this->assertSame('upload', SecurityScan::sole()->trigger);
        $this->assertSame(['domain' => 'kahveatolyesi.com.tr', 'user' => 'kahve', 'paths' => ['public_html/c99.php']], $this->agent->called('scan.files')[0]);
    }

    // --- Parola korumalı klasörler ---

    public function test_protected_folders_keep_hashes_and_removal_has_undo(): void
    {
        $page = Livewire::test(SiteProtect::class, ['site' => $this->site->id])
            ->call('openCreate')
            ->set('path', 'yonetim')
            ->set('logins', [['name' => 'ofis', 'password' => 'kisa']])
            ->call('save')
            ->assertHasErrors('logins')
            ->set('logins', [['name' => 'ofis', 'password' => 'uzun-bir-parola']])
            ->call('save')
            ->assertHasNoErrors();
        $dir = ProtectedDirectory::sole();
        $this->assertSame('/yonetim/', $dir->path);
        $sent = $this->agent->called('web.protect.apply')[0]['dirs'][0];
        $this->assertSame('ofis', $sent['users'][0]['name']);
        $this->assertSame(crypt('uzun-bir-parola', $sent['users'][0]['hash']), $sent['users'][0]['hash']);
        $this->assertStringNotContainsString('uzun-bir-parola', json_encode($dir->getAttributes()));

        // Editing without a new password keeps the old hash.
        $page->call('openEdit', $dir->id)->set('realm', 'Yönetim')->call('save')->assertHasNoErrors();
        $this->assertSame($sent['users'][0]['hash'], $this->agent->called('web.protect.apply')[1]['dirs'][0]['users'][0]['hash']);

        $page->call('delete', $dir->id)
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'restore', 'args' => [$dir->id]]);
        $this->assertSame([], $this->agent->called('web.protect.apply')[2]['dirs']);
        $page->call('restore', $dir->id);
        $this->assertSame(1, ProtectedDirectory::count());
    }

    // --- Giriş güvenliği ---

    public function test_ssh_settings_apply_with_undo(): void
    {
        $this->agent->answers['ssh.apply'] = fn ($p) => $p;
        Livewire::test(SecurityIndex::class, ['tab' => 'giris'])
            ->assertSet('rootLogin', 'yes')
            ->set('rootLogin', 'prohibit-password')
            ->set('passwordAuth', false)
            ->call('saveSsh')
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'undoSsh', 'args' => ['yes', true]])
            ->call('undoSsh', 'yes', true);
        $this->assertSame([['root_login' => 'prohibit-password', 'password_auth' => false], ['root_login' => 'yes', 'password_auth' => true]], $this->agent->called('ssh.apply'));

        $this->agent->answers['ssh.apply'] = AgentException::rpcError('ssh.apply', AgentException::INVALID_PARAMS, 'no administrator account has an ssh key');
        Livewire::test(SecurityIndex::class, ['tab' => 'giris'])
            ->set('passwordAuth', false)
            ->call('saveSsh')
            ->assertHasErrors('rootLogin')
            ->assertSee('SSH anahtarı olan bir yönetici hesabı yok');
    }

    public function test_two_factor_setup_login_and_recovery_codes(): void
    {
        $google = new Google2FA;
        $page = Livewire::test(AccountSecurity::class)
            ->call('startSetup')
            ->assertSet('settingUp', true)
            ->assertSee('<svg', false);
        $secret = $page->get('secret');
        $page->set('code', '000000')->call('confirmSetup')->assertHasErrors('code')
            ->set('code', $google->getCurrentOtp($secret))->call('confirmSetup')->assertHasNoErrors();
        $codes = $page->get('recoveryCodes');
        $this->assertCount(10, $codes);
        $this->admin->refresh();
        $this->assertTrue($this->admin->hasTwoFactor());
        $this->assertNotContains(str_replace('-', '', $codes[0]), $this->admin->two_factor_recovery_codes);

        // The password alone no longer signs in. (This panel's setup is
        // done, so the sign-in goes to Genel Bakış.)
        app(FirstRun::class)->complete();
        auth()->logout();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password'])->assertRedirect(route('login.two-factor'));
        $this->assertGuest();
        $this->get('/login/dogrulama')->assertOk();
        $this->post('/login/dogrulama', ['code' => '123456'])->assertSessionHasErrors('code');
        $this->assertGuest();
        $this->post('/login/dogrulama', ['code' => $codes[0]])->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($this->admin);
        $this->assertSame(9, app(TwoFactor::class)->recoveryCodesLeft($this->admin->fresh()));

        // A recovery code works once.
        auth()->logout();
        $this->post('/login', ['email' => $this->admin->email, 'password' => 'password']);
        $this->post('/login/dogrulama', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_required_two_factor_sends_accounts_to_set_it_up(): void
    {
        Livewire::test(SecurityIndex::class, ['tab' => 'giris'])
            ->set('require2fa', 'admins')
            ->call('saveTwoFactor')
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'undoTwoFactor', 'args' => ['none']]);

        $this->get('/sites')->assertRedirect(route('account.security'));
        $this->get('/hesap/guvenlik')->assertOk()->assertSee('zorunlu tuttu');

        // A customer is not an administrator: not asked.
        $customer = Customer::factory()->withLogin()->create();
        $this->actingAs($customer->user)->get('/')->assertOk();

        // And it cannot be turned off while required.
        $this->actingAs($this->admin);
        app(TwoFactor::class)->enable($this->admin, $secret = app(TwoFactor::class)->newSecret(), (new Google2FA)->getCurrentOtp($secret));
        Livewire::test(AccountSecurity::class)->call('openDisable')->set('password', 'password')->call('disable')->assertHasErrors('password');
        $this->assertTrue($this->admin->fresh()->hasTwoFactor());
        $this->get('/sites')->assertOk();
    }

    public function test_panel_login_failures_are_limited_per_address(): void
    {
        auth()->logout();
        foreach (range(1, 30) as $i) {
            $this->post('/login', ['email' => "kimse{$i}@example.test", 'password' => 'yanlis']);
        }
        $this->post('/login', ['email' => 'baska@example.test', 'password' => 'yanlis'])->assertSessionHasErrors('email');
        $this->assertStringContainsString('Çok fazla deneme', session('errors')->first('email'));
    }

    // --- Fail2ban ---

    public function test_fail2ban_bans_show_as_ip_blocks_and_lifting_them_has_undo(): void
    {
        $this->agent->answers['fail2ban.status'] = ['installed' => true, 'running' => true, 'jails' => [
            ['name' => 'sshd', 'banned' => [['address' => '198.51.100.23', 'since' => '2026-10-19T12:00:00Z', 'until' => now()->addHour()->toIso8601String()]]],
        ]];
        $this->assertSame(1, app(Fail2ban::class)->sync());
        $block = IpBlock::sole();
        $this->assertSame(['fail2ban', 'sshd'], [$block->source, $block->kind]);

        Livewire::test(LiveMonitor::class, ['tab' => 'saldirilar'])
            ->call('unblockMany', [$block->id])
            ->assertDispatched('toast', fn ($n, $p) => $p['undo'] === ['method' => 'reblockMany', 'args' => [[$block->id]]])
            ->call('reblockMany', [$block->id]);
        $this->assertSame([['jail' => 'sshd', 'address' => '198.51.100.23']], $this->agent->called('fail2ban.unban'));
        $this->assertSame([['jail' => 'sshd', 'address' => '198.51.100.23']], $this->agent->called('fail2ban.ban'));
        $this->assertTrue($block->fresh()->isActive());

        // Fail2ban lifted it on its own.
        $this->agent->answers['fail2ban.status']['jails'] = [];
        app(Fail2ban::class)->sync();
        $this->assertFalse($block->fresh()->isActive());
    }

    public function test_fail2ban_jails_follow_the_server_without_a_reinstall(): void
    {
        $this->agent->answers['fail2ban.status'] = ['installed' => true, 'running' => true, 'current' => true, 'jails' => []];
        app(Fail2ban::class)->sync();
        $this->assertSame([], $this->agent->called('fail2ban.refresh'));

        // The mail server came after Fail2ban: the minute sync rewrites the jails.
        $this->agent->answers['fail2ban.status']['current'] = false;
        $this->agent->answers['fail2ban.refresh'] = ['changed' => true];
        app(Fail2ban::class)->sync();
        $this->assertCount(1, $this->agent->called('fail2ban.refresh'));
        $this->assertDatabaseHas('audit_entries', ['via' => 'system', 'message' => 'Fail2ban kuralları sunucudaki değişikliğe göre güncellendi.']);

        // Busy with another software operation: tried again the next minute.
        $this->agent->answers['fail2ban.refresh'] = AgentException::rpcError('fail2ban.refresh', -32005, 'another software operation is running');
        app(Fail2ban::class)->sync();
        app(Fail2ban::class)->sync();
        $this->assertCount(3, $this->agent->called('fail2ban.refresh'));

        // Fail2ban refused the jails: not retried for an hour.
        $this->agent->answers['fail2ban.refresh'] = AgentException::rpcError('fail2ban.refresh', -32000, 'fail2ban-client --test failed');
        app(Fail2ban::class)->sync();
        app(Fail2ban::class)->sync();
        $this->assertCount(4, $this->agent->called('fail2ban.refresh'));
        $this->travel(61)->minutes();
        app(Fail2ban::class)->sync();
        $this->assertCount(5, $this->agent->called('fail2ban.refresh'));
    }

    // --- Customers ---

    public function test_customers_get_their_own_sites_security_within_the_package(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $mine = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->finding();
        $this->finding(['site_id' => $mine->id, 'path' => 'public_html/shell.php']);
        $this->actingAs($customer->user);

        $this->get('/sites/'.$mine->id.'/waf')->assertOk();
        $this->get('/sites/'.$mine->id.'/tarama')->assertOk()->assertSee('shell.php')->assertDontSee('x.php');
        $this->get('/sites/'.$mine->id.'/korumali-klasorler')->assertOk();
        $this->get('/sites/'.$this->site->id.'/waf')->assertNotFound();
        $this->get('/sites/'.$this->site->id.'/tarama')->assertNotFound();
        $this->get('/guvenlik')->assertForbidden();
        $this->get('/hesap/guvenlik')->assertOk();
        $this->assertSame(1, Scans::attentionCount($customer->user));

        // Blocking an address for the whole server stays with administrators.
        Livewire::test(SiteWaf::class, ['site' => $mine->id])->call('openBlock', '203.0.113.9')->assertForbidden();
        // Someone else's finding is out of reach.
        $theirs = SecurityFinding::where('site_id', $this->site->id)->sole();
        Livewire::test(SiteScan::class, ['site' => $mine->id])->call('ignoreFinding', $theirs->id);
        $this->assertSame('open', $theirs->fresh()->status);

        $customer->package->update(['allow_security' => false]);
        $this->actingAs($customer->user->fresh());
        $this->get('/sites/'.$mine->id.'/waf')->assertForbidden();
        $this->get('/sites/'.$mine->id)->assertOk()->assertSee('Paketinizde yok');
    }

    // --- Serfex AI ---

    public function test_serfex_ai_reads_findings_and_waf_events(): void
    {
        $this->finding();
        $conversation = AiConversation::create(['user_id' => $this->admin->id, 'title' => 'Güvenlik']);
        [$json, $error] = app(Tools::class)->run('get_security', [], 't1', $conversation);
        $this->assertFalse($error);
        $result = json_decode($json, true);
        $this->assertSame(1, $result['open_findings_total']);
        $this->assertSame('critical', $result['open_findings'][0]['severity']);
        $this->assertSame('kahveatolyesi.com.tr', $result['waf_events'][0]['site']);
        $this->assertSame(['942100 SQL Injection Attack Detected via libinjection'], $result['waf_events'][0]['rules']);
        $this->assertSame('off', $result['firewall']);
    }

    public function test_the_site_page_links_its_security_tools(): void
    {
        $this->finding();
        $this->get('/sites/'.$this->site->id)->assertOk()
            ->assertSee(route('sites.waf', $this->site))
            ->assertSee(route('sites.scan', $this->site))
            ->assertSee(route('sites.protect', $this->site));
    }
}
