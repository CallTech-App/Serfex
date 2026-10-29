<?php

namespace Tests\Feature;

use App\Jobs\RemoveLaravelApp;
use App\Livewire\Account\AccountGit;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\Sections\Deployments;
use App\Livewire\Settings\GitProviderSettings;
use App\Livewire\Sites\SiteGit;
use App\Models\Customer;
use App\Models\GitAccount;
use App\Models\GitRepository;
use App\Models\LaravelApp;
use App\Models\Setting;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Git\GitAccounts;
use App\Services\Git\GitProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Git sağlayıcıları: an administrator sets up the OAuth applications,
 * people connect their GitHub, GitLab or Bitbucket accounts, pick a
 * repository and branch from lists, and Serfex adds (and later removes)
 * the deploy key and webhook on it.
 */
class GitProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIK serfex@kahve.com.tr';

    private FakeAgent $agent;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'site.git.key' => ['public_key' => self::KEY],
            'site.git.remove' => ['removed' => true],
            'laravel.key' => ['public_key' => self::KEY],
            'php.versions' => ['8.3', '8.4'],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr', 'php_version' => '8.4']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->admin = User::factory()->create(['name' => 'Aylin']);
        $this->actingAs($this->admin);
    }

    private function github(): void
    {
        app(GitProviders::class)->save('github', ['client_id' => 'Iv1.abc123', 'client_secret' => 'gizli-anahtar']);
    }

    private function account(string $provider = 'github', array $values = []): GitAccount
    {
        return GitAccount::create(['user_id' => $this->admin->id, 'provider' => $provider, 'remote_id' => '42', 'username' => 'aylin', 'access_token' => 'gho_token', ...$values]);
    }

    /**
     * A GitHub API answering for two repositories of aylin.
     *
     * @param  array<string, mixed>  $extra
     */
    private function fakeGitHub(array $extra = []): void
    {
        Http::fake($extra + [
            'api.github.com/user/repos*' => Http::response([
                ['full_name' => 'aylin/kahve-site', 'ssh_url' => 'git@github.com:aylin/kahve-site.git', 'private' => true, 'default_branch' => 'production', 'permissions' => ['admin' => true]],
                ['full_name' => 'acme/eski', 'ssh_url' => 'git@github.com:acme/eski.git', 'private' => false, 'default_branch' => 'main', 'archived' => true],
                ['full_name' => 'acme/web', 'ssh_url' => 'git@github.com:acme/web.git', 'private' => false, 'default_branch' => 'main', 'permissions' => ['admin' => false]],
            ]),
            'api.github.com/repos/aylin/kahve-site/branches*' => Http::response([['name' => 'main'], ['name' => 'production']]),
            'api.github.com/repos/aylin/kahve-site/keys' => Http::response(['id' => 9001], 201),
            'api.github.com/repos/aylin/kahve-site/hooks' => Http::response(['id' => 7001], 201),
            'api.github.com/repos/aylin/kahve-site/keys/9001' => Http::response(null, 204),
            'api.github.com/repos/aylin/kahve-site/hooks/7001' => Http::response(null, 204),
        ]);
    }

    public function test_an_administrator_sets_up_github_with_undo(): void
    {
        Setting::put('panel.hostname', 'panel.kahve.com.tr');
        $page = Livewire::test(GitProviderSettings::class)
            ->assertSee('Kapalı')
            ->call('edit', 'github')
            ->assertSee('https://panel.kahve.com.tr:8443/git/callback/github')
            ->set('form.github.client_id', 'Iv1.abc123')
            ->call('save')
            ->assertHasErrors('form.github.client_secret')
            ->set('form.github.client_secret', 'gizli-anahtar')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('editing', null)
            ->assertDispatched('toast');

        $saved = Setting::get(GitProviders::KEY)['github'];
        $this->assertSame('Iv1.abc123', $saved['client_id']);
        $this->assertNotSame('gizli-anahtar', $saved['client_secret']);
        $this->assertSame('gizli-anahtar', Crypt::decryptString($saved['client_secret']));
        $this->assertTrue(app(GitProviders::class)->isConfigured('github'));
        $page->assertSee('Açık')->assertSee('Iv1.abc123');

        // Undo puts it back as it was: off.
        $page->call('restore', 'github', ['client_id' => '', 'client_secret' => null, 'url' => '']);
        $this->assertFalse(app(GitProviders::class)->isConfigured('github'));
    }

    public function test_a_gitlab_server_of_your_own_and_turning_a_provider_off(): void
    {
        $page = Livewire::test(GitProviderSettings::class)->call('edit', 'gitlab')
            ->set('form.gitlab.url', 'https://gitlab.ornek.com.tr/yol')
            ->set('form.gitlab.client_id', 'app-id')->set('form.gitlab.client_secret', 'secret')
            ->call('save')->assertHasErrors('form.gitlab.url')
            ->set('form.gitlab.url', 'https://gitlab.ornek.com.tr/')
            ->call('save')->assertHasNoErrors();
        $gitlab = app(GitProviders::class)->get('gitlab');
        $this->assertSame('https://gitlab.ornek.com.tr', $gitlab->webUrl());
        $this->assertStringStartsWith('https://gitlab.ornek.com.tr/oauth/authorize?', $gitlab->authorizeUrl('https://x/cb', 'st'));

        $page->set('turningOff', 'gitlab')->call('turnOff');
        $this->assertFalse(app(GitProviders::class)->isConfigured('gitlab'));
    }

    public function test_only_full_administrators_reach_the_settings(): void
    {
        $customer = User::where('customer_id', Customer::factory()->withLogin()->create()->id)->sole();
        $this->actingAs($customer)->get(route('settings.git'))->assertForbidden();
        $this->actingAs($this->admin)->get(route('settings.git'))->assertOk()->assertSee('Git sağlayıcıları');
    }

    public function test_connecting_an_account_with_oauth(): void
    {
        $this->github();
        Http::fake([
            'github.com/login/oauth/access_token' => Http::response(['access_token' => 'gho_new', 'scope' => 'repo,admin:repo_hook', 'token_type' => 'bearer']),
            'api.github.com/user' => Http::response(['id' => 15074140, 'login' => 'aylin']),
        ]);

        $redirect = $this->get(route('git.connect', ['github', 'return' => '/sites/'.$this->site->id.'/git?source=github']));
        $redirect->assertRedirect();
        $location = $redirect->headers->get('Location');
        $this->assertStringStartsWith('https://github.com/login/oauth/authorize?', $location);
        parse_str(parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('Iv1.abc123', $query['client_id']);
        $this->assertSame('repo admin:repo_hook', $query['scope']);
        $this->assertStringEndsWith('/git/callback/github', $query['redirect_uri']);

        // A wrong state is refused and nothing is saved.
        $this->get(route('git.callback', ['github', 'code' => 'abc', 'state' => 'yanlis']))->assertRedirect()->assertSessionHas('toast');
        $this->assertSame(0, GitAccount::count());

        $this->get(route('git.connect', ['github', 'return' => '/sites/'.$this->site->id.'/git?source=github']));
        $state = session('git_oauth')['state'];
        $this->get(route('git.callback', ['github', 'code' => 'abc', 'state' => $state]))
            ->assertRedirect('/sites/'.$this->site->id.'/git?source=github')
            ->assertSessionHas('toast', 'GitHub hesabı bağlandı: aylin');

        $account = GitAccount::sole();
        $this->assertSame(['github', '15074140', 'aylin', $this->admin->id], [$account->provider, $account->remote_id, $account->username, $account->user_id]);
        $this->assertSame('gho_new', $account->access_token);
        $this->assertNotSame('gho_new', DB::table('git_accounts')->value('access_token'));
        Http::assertSent(fn (Request $r) => $r->url() === 'https://github.com/login/oauth/access_token' && $r['code'] === 'abc' && $r['client_secret'] === 'gizli-anahtar');

        // The state is used once.
        $this->get(route('git.callback', ['github', 'code' => 'abc', 'state' => $state]))->assertSessionHas('toast', 'Bağlantı isteği geçersiz ya da süresi dolmuş; yeniden deneyin.');
    }

    public function test_the_flow_runs_on_the_registered_panel_address_and_only_returns_inside_the_panel(): void
    {
        $this->github();
        Setting::put('panel.hostname', 'panel.kahve.com.tr');
        $this->get('http://localhost/git/connect/github?return=/account/git')
            ->assertRedirect('https://panel.kahve.com.tr:8443/git/connect/github?return=/account/git');

        Setting::put('panel.hostname', null);
        $this->get(route('git.connect', ['github', 'return' => '//kotu.example/']))->assertRedirect();
        $this->assertSame('/account/git', session('git_oauth')['return']);
    }

    public function test_a_provider_that_is_off_cannot_be_connected_and_read_only_accounts_cannot_connect(): void
    {
        $this->get(route('git.connect', ['gitlab', 'return' => '/account/git']))->assertRedirect('/account/git')->assertSessionHas('toast');
        $this->github();
        $this->actingAs(User::factory()->create(['role' => 'readonly']))
            ->get(route('git.connect', ['github']))->assertRedirect('/account/git')->assertSessionHas('toast', 'Salt okunur hesaplar Git hesabı bağlayamaz.');
    }

    public function test_the_account_page_lists_and_disconnects_accounts(): void
    {
        Livewire::test(AccountGit::class)->assertSee('Git hesabı bağlantısı açık değil');
        $this->github();
        Livewire::test(AccountGit::class)->assertSee('GitHub ile bağlan');
        $account = $this->account();
        Livewire::test(AccountGit::class)
            ->assertSee('aylin')
            ->set('disconnectingId', $account->id)
            ->assertSee('Git hesabının bağlantısını kaldır')
            ->call('disconnect')
            ->assertDispatched('toast');
        $this->assertSame(0, GitAccount::count());

        // Another user's account is not theirs to remove.
        $other = GitAccount::create(['user_id' => User::factory()->create()->id, 'provider' => 'github', 'remote_id' => '1', 'username' => 'baska', 'access_token' => 't']);
        Livewire::test(AccountGit::class)->set('disconnectingId', $other->id)->call('disconnect');
        $this->assertModelExists($other);
    }

    public function test_customers_connect_their_own_accounts(): void
    {
        $this->github();
        $customer = User::where('customer_id', Customer::factory()->withLogin()->create()->id)->sole();
        $this->actingAs($customer)->get(route('account.git'))->assertOk()->assertSee('GitHub ile bağlan');
    }

    public function test_the_site_git_page_offers_the_picker_and_asks_to_connect_first(): void
    {
        Livewire::test(SiteGit::class, ['site' => $this->site->id])->assertSet('gitSource', '')->assertDontSee('Adresi yazarak');

        $this->github();
        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->assertSet('gitSource', 'github')
            ->assertSee('GitHub ile bağlan')
            ->assertSee('/git/connect/github?return=%2Fsites%2F'.$this->site->id.'%2Fgit%3Fsource%3Dgithub', false)
            ->set('gitSource', '')
            ->assertSee('Depo adresi');
    }

    public function test_picking_a_repository_adds_the_deploy_key_and_webhook(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();
        Setting::put('panel.hostname', 'panel.kahve.com.tr');

        $page = Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->assertViewHas('picker', fn ($p) => array_column($p['repos'], 'name') === ['aylin/kahve-site', 'acme/web'])
            ->call('connect')
            ->assertHasErrors('pickedRepo')
            ->set('pickedRepo', 'aylin/kahve-site')
            ->assertSet('branch', 'production')
            ->assertSet('pickedBranches', ['main', 'production'])
            ->call('connect')
            ->assertHasNoErrors()
            ->assertSet('showingKey', false);

        $repo = GitRepository::sole();
        $this->assertSame(['git@github.com:aylin/kahve-site.git', 'production', 'aylin/kahve-site', '9001', '7001'],
            [$repo->repository, $repo->branch, $repo->remote_repository, $repo->remote_key_id, $repo->remote_hook_id]);
        $this->assertSame(GitAccount::sole()->id, $repo->git_account_id);
        $this->assertSame(self::KEY, $repo->deploy_key);
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.github.com/repos/aylin/kahve-site/keys'
            && $r['read_only'] === true && $r['key'] === self::KEY && $r['title'] === 'Serfex · kahve.com.tr' && $r->hasHeader('Authorization', 'Bearer gho_token'));
        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->url() === 'https://api.github.com/repos/aylin/kahve-site/hooks'
            && $r['events'] === ['push'] && $r['config']['url'] === 'https://panel.kahve.com.tr:8443/hooks/deploy/'.$repo->webhook_token
            && $r['config']['insecure_ssl'] === '1' && $r['config']['secret'] === $repo->webhook_token);
        $page->assertSee('Webhook GitHub deposuna eklendi.');

        // Disconnecting removes both from the repository.
        $page->call('disconnect');
        $this->assertSame(0, GitRepository::count());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.github.com/repos/aylin/kahve-site/keys/9001');
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && $r->url() === 'https://api.github.com/repos/aylin/kahve-site/hooks/7001');
    }

    public function test_a_refused_deploy_key_is_shown_to_add_by_hand(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub([
            'api.github.com/repos/aylin/kahve-site/keys' => Http::response(['message' => 'key is already in use'], 422),
            'api.github.com/repos/aylin/kahve-site/hooks' => Http::response(['message' => 'Not Found'], 404),
        ]);

        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->set('pickedRepo', 'aylin/kahve-site')
            ->call('connect')
            ->assertSet('showingKey', true)
            ->assertSet('publicKey', self::KEY)
            ->assertSee('key is already in use')
            ->assertSee('Webhook eklenemedi');

        $repo = GitRepository::sole();
        $this->assertNull($repo->remote_key_id);
        $this->assertNull($repo->remote_hook_id);
        $this->assertSame('aylin/kahve-site', $repo->remote_repository);
    }

    public function test_nothing_is_kept_when_the_server_cannot_make_the_deploy_key(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();
        $this->agent->answers['site.git.key'] = new AgentException('site kahve.com.tr does not exist', AgentException::INVALID_PARAMS);

        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->set('pickedRepo', 'aylin/kahve-site')
            ->call('connect')
            ->assertDispatched('toast');

        $this->assertSame(0, GitRepository::count());
        Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
    }

    public function test_a_repository_not_in_the_list_cannot_be_picked(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();
        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->set('pickedRepo', 'baskasi/gizli')
            ->assertSet('pickedRepo', '')
            ->call('connect')
            ->assertHasErrors('pickedRepo');
        $this->assertSame(0, GitRepository::count());
    }

    public function test_an_expired_sign_in_asks_to_connect_again(): void
    {
        $this->github();
        $this->account();
        Http::fake(['api.github.com/*' => Http::response(['message' => 'Bad credentials'], 401)]);
        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->assertSee('GitHub oturumunun süresi dolmuş')
            ->assertSee('Yeniden bağlan');
    }

    public function test_gitlab_tokens_are_refreshed_before_they_run_out(): void
    {
        app(GitProviders::class)->save('gitlab', ['client_id' => 'app-id', 'client_secret' => 'secret', 'url' => 'https://gitlab.com']);
        $account = $this->account('gitlab', ['access_token' => 'old', 'refresh_token' => 'refresh-1', 'expires_at' => now()->subMinute()]);
        Http::fake([
            'gitlab.com/oauth/token' => Http::response(['access_token' => 'new', 'refresh_token' => 'refresh-2', 'expires_in' => 7200]),
            'gitlab.com/api/v4/projects*' => Http::response([
                ['path_with_namespace' => 'grup/alt/uygulama', 'ssh_url_to_repo' => 'git@gitlab.com:grup/alt/uygulama.git', 'visibility' => 'private', 'default_branch' => 'main',
                    'permissions' => ['project_access' => null, 'group_access' => ['access_level' => 40]]],
            ]),
        ]);

        $repos = app(GitAccounts::class)->repositories($account);

        $this->assertSame([['name' => 'grup/alt/uygulama', 'ssh_url' => 'git@gitlab.com:grup/alt/uygulama.git', 'private' => true, 'default_branch' => 'main', 'admin' => true]], $repos);
        $account->refresh();
        $this->assertSame(['new', 'refresh-2'], [$account->access_token, $account->refresh_token]);
        $this->assertTrue($account->expires_at->isFuture());
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://gitlab.com/api/v4/projects') && $r->hasHeader('Authorization', 'Bearer new'));
    }

    public function test_bitbucket_lists_repositories_and_adds_a_deploy_key(): void
    {
        app(GitProviders::class)->save('bitbucket', ['client_id' => 'key', 'client_secret' => 'secret']);
        $account = $this->account('bitbucket');
        Http::fake([
            'api.bitbucket.org/2.0/repositories?*' => Http::response(['values' => [
                ['full_name' => 'ekip/site', 'is_private' => true, 'mainbranch' => ['name' => 'master'],
                    'links' => ['clone' => [['name' => 'https', 'href' => 'https://bitbucket.org/ekip/site.git'], ['name' => 'ssh', 'href' => 'git@bitbucket.org:ekip/site.git']]]],
            ]]),
            'api.bitbucket.org/2.0/repositories/ekip/site/deploy-keys' => Http::response(['id' => 123], 200),
            'api.bitbucket.org/2.0/repositories/ekip/site/hooks' => Http::response(['uuid' => '{abc-def}'], 201),
        ]);

        $this->assertSame('git@bitbucket.org:ekip/site.git', app(GitAccounts::class)->repository($account, 'ekip/site')['ssh_url']);
        $repo = GitRepository::create(['site_id' => $this->site->id, 'repository' => 'git@bitbucket.org:ekip/site.git']);
        $linked = app(GitAccounts::class)->link($repo, $account, 'ekip/site', self::KEY, 'https://panel/hooks/deploy/x', false);

        $this->assertSame(['key' => true, 'hook' => true, 'errors' => []], $linked);
        $this->assertSame(['123', '{abc-def}'], [$repo->fresh()->remote_key_id, $repo->fresh()->remote_hook_id]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hooks') && $r['events'] === ['repo:push'] && $r['skip_cert_verification'] === true && $r['secret'] === $repo->fresh()->webhook_token);
    }

    public function test_laravel_install_from_a_picked_repository(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();

        Livewire::withoutLazyLoading()->test(LaravelIndex::class)->call('openInstall', $this->site->id)
            ->set('source', 'git')
            ->assertViewHas('picker', fn ($p) => in_array('aylin/kahve-site', array_column($p['repos'], 'name'), true))
            ->set('pickedRepo', 'aylin/kahve-site')
            ->set('database', 'none')
            ->call('install')
            ->assertHasNoErrors()
            ->assertRedirect();

        $app = LaravelApp::sole();
        $this->assertSame(['git@github.com:aylin/kahve-site.git', 'production', 'aylin/kahve-site', '9001', '7001', self::KEY],
            [$app->repository, $app->branch, $app->remote_repository, $app->remote_key_id, $app->remote_hook_id, $app->deploy_key]);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/hooks') && $r['events'] === ['push', 'pull_request']);

        // Removing the application takes them off the repository again.
        (new RemoveLaravelApp($app))->handle($this->agent);
        $this->assertSame(0, LaravelApp::count());
        Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/keys/9001'));
    }

    public function test_laravel_install_falls_back_to_the_key_step_when_github_refuses_the_key(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub(['api.github.com/repos/aylin/kahve-site/keys' => Http::response(['message' => 'Not Found'], 404)]);

        $page = Livewire::withoutLazyLoading()->test(LaravelIndex::class)->call('openInstall', $this->site->id)
            ->set('source', 'git')->set('pickedRepo', 'aylin/kahve-site')->set('database', 'none')
            ->call('install')
            ->assertSet('step', 'key')
            ->assertSee('Dağıtım anahtarı eklenemedi');
        $this->assertSame(0, LaravelApp::count());

        $page->call('install')->assertRedirect();
        $app = LaravelApp::sole();
        $this->assertNull($app->remote_key_id);
        $this->assertSame('7001', $app->remote_hook_id);
    }

    public function test_laravel_install_can_still_type_the_address(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();
        Livewire::withoutLazyLoading()->test(LaravelIndex::class)->call('openInstall', $this->site->id)
            ->set('source', 'git')->set('typeAddress', true)
            ->set('repository', 'aylin/acik')->set('database', 'none')
            ->call('install')->assertRedirect();
        $this->assertSame('https://github.com/aylin/acik.git', LaravelApp::sole()->repository);
        $this->assertNull(LaravelApp::sole()->git_account_id);
    }

    public function test_coming_back_from_github_reopens_the_install_form(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();
        $this->get(route('laravel.index', ['site' => $this->site->id, 'source' => 'git', 'provider' => 'github']))
            ->assertOk()->assertSee('Laravel uygulaması kur')->assertSee('kahve-site')->assertSee('Depo adresini elle yaz');
    }

    public function test_connecting_a_picked_repository_to_an_existing_laravel_app(): void
    {
        $this->github();
        $this->account();
        $this->fakeGitHub();
        $app = LaravelApp::factory()->for($this->site)->create(['repository' => null, 'branch' => null]);

        Livewire::withoutLazyLoading()->test(Deployments::class, ['appId' => $app->id])
            ->call('openConnect')
            ->assertViewHas('picker', fn ($p) => in_array('aylin/kahve-site', array_column($p['repos'], 'name'), true))
            ->set('pickedRepo', 'aylin/kahve-site')
            ->call('connect')
            ->assertSet('connecting', false)
            ->assertDispatched('toast');

        $app->refresh();
        $this->assertSame(['git@github.com:aylin/kahve-site.git', 'production', '9001', '7001'], [$app->repository, $app->branch, $app->remote_key_id, $app->remote_hook_id]);
    }
}
