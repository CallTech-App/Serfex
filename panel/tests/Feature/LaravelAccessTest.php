<?php

namespace Tests\Feature;

use App\Enums\DeploymentStatus;
use App\Jobs\DeployLaravelApp;
use App\Livewire\Laravel\LaravelIndex;
use App\Livewire\Laravel\LaravelShow;
use App\Livewire\Laravel\Sections\Access;
use App\Livewire\Laravel\Sections\Environment;
use App\Livewire\Sites\SiteIndex;
use App\Models\LaravelApiToken;
use App\Models\LaravelApp;
use App\Models\LaravelDeployment;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class LaravelAccessTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent(['laravel.status' => ['exists' => true, 'processes' => [], 'info' => ['packages' => []]]]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    private function laravelApp(string $domain = 'api.hizlikargo.app'): LaravelApp
    {
        $site = Site::factory()->for(SystemUser::factory()->state(['name' => explode('.', $domain)[0]]))->create(['domain' => $domain, 'web_root' => 'laravel/current/public']);

        return LaravelApp::factory()->for($site)->create();
    }

    private function member(LaravelApp $app, string $role): User
    {
        $user = User::factory()->member()->create();
        $app->members()->create(['user_id' => $user->id, 'role' => $role]);

        return $user;
    }

    public function test_members_see_only_their_applications_and_no_server_pages(): void
    {
        $mine = $this->laravelApp();
        $this->laravelApp('magaza.com.tr');
        $user = $this->member($mine, 'developer');
        $this->actingAs($user);

        $this->get(route('dashboard'))->assertRedirect(route('laravel.index'));
        $this->get(route('sites.index'))->assertForbidden();
        $this->get(route('dns.index'))->assertForbidden();
        $this->get(route('laravel.index'))->assertOk()->assertSee('api.hizlikargo.app')->assertDontSee('magaza.com.tr')
            ->assertDontSee('Laravel uygulaması kur')->assertDontSee('Alan Adları ve DNS');
        $this->get(route('laravel.show', $mine))->assertOk();
        $this->get(route('laravel.show', LaravelApp::where('id', '!=', $mine->id)->first()))->assertForbidden();

        Livewire::test(LaravelIndex::class)->call('scan')->assertForbidden();
        Livewire::test(SiteIndex::class)->assertForbidden();
    }

    public function test_viewers_see_but_cannot_change(): void
    {
        $app = $this->laravelApp();
        LaravelDeployment::create(['laravel_app_id' => $app->id, 'number' => 1, 'kind' => 'install', 'status' => DeploymentStatus::Succeeded, 'run' => 'install-1']);
        $this->actingAs($this->member($app, 'viewer'));

        $this->get(route('laravel.show', $app))->assertOk()->assertDontSee('Ortam (.env)')->assertDontSee('Yedekler')->assertDontSee('Komut çalıştır');
        $page = Livewire::test(LaravelShow::class, ['app' => $app->id]);
        $page->call('show', 'gunlukler')->assertSet('section', 'gunlukler');
        $page->call('show', 'ortam')->assertSet('section', 'gunlukler');
        $page->call('openDeploy')->assertForbidden();
        Livewire::withoutLazyLoading()->test(Environment::class, ['appId' => $app->id])->assertForbidden();
        Queue::assertNotPushed(DeployLaravelApp::class);
    }

    public function test_developers_deploy_but_only_owners_manage_people(): void
    {
        $app = $this->laravelApp();
        $this->actingAs($this->member($app, 'developer'));

        Livewire::test(LaravelShow::class, ['app' => $app->id])->call('openDeploy')->call('deploy')->assertHasNoErrors();
        Queue::assertPushed(DeployLaravelApp::class);
        Livewire::withoutLazyLoading()->test(Access::class, ['appId' => $app->id])->assertDontSee('Kişi ekle')->call('openAdd')->assertForbidden();
        Livewire::test(LaravelShow::class, ['app' => $app->id])->call('openRemove')->assertForbidden();
    }

    public function test_owners_invite_people_who_set_a_password(): void
    {
        $app = $this->laravelApp();
        $this->actingAs($this->member($app, 'owner'));

        $page = Livewire::withoutLazyLoading()->test(Access::class, ['appId' => $app->id])
            ->call('openAdd')->set('email', 'Emre@HizliKargo.app')->set('role', 'developer')->call('add')->assertHasNoErrors();
        $link = $page->get('secret');
        $this->assertStringContainsString('/davet/', $link);
        $invited = User::where('email', 'emre@hizlikargo.app')->sole();
        $this->assertFalse($invited->is_admin);
        $this->assertTrue($invited->isInvited());
        $this->assertSame('developer', $invited->laravelRole($app));
        $page->assertSee('Davet bekliyor');

        // Admins are owners everywhere and cannot be added.
        $admin = User::factory()->create(['email' => 'sercan@servik.test']);
        $page->call('openAdd')->set('email', 'sercan@servik.test')->call('add')->assertHasErrors('email');

        Auth::logout();
        $path = parse_url($link, PHP_URL_PATH);
        $this->get($path)->assertOk()->assertSee('emre@hizlikargo.app');
        $this->post($path, ['name' => 'Emre Kaya', 'password' => 'kisa', 'password_confirmation' => 'kisa'])->assertSessionHasErrors('password');
        $this->post($path, ['name' => 'Emre Kaya', 'password' => 'uzun-bir-parola', 'password_confirmation' => 'uzun-bir-parola'])->assertRedirect(route('laravel.index'));
        $this->assertAuthenticatedAs($invited->fresh());
        $this->assertFalse($invited->fresh()->isInvited());
        $this->get($path)->assertNotFound();
        $this->assertSame('owner', $admin->laravelRole($app));
    }

    public function test_role_change_and_removal_can_be_undone(): void
    {
        $app = $this->laravelApp();
        $user = $this->member($app, 'viewer');
        $this->actingAs(User::factory()->create());
        $member = $app->members()->sole();

        $page = Livewire::withoutLazyLoading()->test(Access::class, ['appId' => $app->id]);
        $page->call('setRole', $member->id, 'developer')->assertDispatched('toast');
        $this->assertSame('developer', $member->fresh()->role);
        $page->call('remove', $member->id);
        $this->assertNull($user->fresh()->laravelRole($app));
        $page->call('restoreMember', ['user_id' => $user->id, 'role' => 'developer']);
        $this->assertSame('developer', $user->fresh()->laravelRole($app));
    }

    public function test_api_tokens_deploy_their_own_application_only(): void
    {
        $app = $this->laravelApp();
        $other = $this->laravelApp('magaza.com.tr');
        [$token, $plain] = LaravelApiToken::issue($app, 'GitHub Actions');
        $this->assertSame(64, strlen($token->token_hash));
        $this->assertNotSame($plain, $token->token_hash);

        $this->postJson('/api/v1/apps/api.hizlikargo.app/deploy')->assertUnauthorized();
        $this->postJson('/api/v1/apps/magaza.com.tr/deploy', [], ['Authorization' => 'Bearer '.$plain])->assertForbidden();
        $this->postJson('/api/v1/apps/api.hizlikargo.app/deploy', ['ref' => '../x'], ['Authorization' => 'Bearer '.$plain])->assertUnprocessable();

        $res = $this->postJson('/api/v1/apps/api.hizlikargo.app/deploy', ['ref' => 'v2.1.0', 'migrate' => false], ['Authorization' => 'Bearer '.$plain, 'User-Agent' => 'curl/8.5'])
            ->assertAccepted()->assertJsonPath('status', 'queued')->assertJsonPath('ref', 'v2.1.0');
        $deployment = $app->deployments()->sole();
        $this->assertSame('API: GitHub Actions', $deployment->triggered_by);
        $this->assertFalse($deployment->migrate);
        Queue::assertPushed(DeployLaravelApp::class);
        $this->assertSame('curl/8.5', $token->fresh()->last_used_agent);
        $this->assertNotNull($token->fresh()->last_used_at);

        $this->postJson('/api/v1/apps/api.hizlikargo.app/deploy', [], ['Authorization' => 'Bearer '.$plain])->assertStatus(409);
        $this->getJson('/api/v1/apps/api.hizlikargo.app/deployments/'.$res->json('deployment'), ['Authorization' => 'Bearer '.$plain])
            ->assertOk()->assertJsonPath('done', false);
        $this->getJson('/api/v1/apps/api.hizlikargo.app/deployments/999', ['Authorization' => 'Bearer '.$plain])->assertNotFound();
        $this->getJson('/api/v1/apps/api.hizlikargo.app', ['Authorization' => 'Bearer '.$plain])->assertOk()->assertJsonPath('domain', 'api.hizlikargo.app');

        $token->update(['revoked_at' => now()]);
        $this->getJson('/api/v1/apps/api.hizlikargo.app', ['Authorization' => 'Bearer '.$plain])->assertUnauthorized();
        $this->assertSame(0, $other->deployments()->count());
    }

    public function test_tokens_are_shown_once_and_revoked_with_undo(): void
    {
        $app = $this->laravelApp();
        $this->actingAs(User::factory()->create());

        $page = Livewire::withoutLazyLoading()->test(Access::class, ['appId' => $app->id])
            ->call('openToken')->set('tokenName', 'Deploy CLI')->call('createToken');
        $plain = $page->get('secret');
        $this->assertStringStartsWith('svk_', $plain);
        $this->assertSame($app->id, LaravelApiToken::findPlain($plain)->laravel_app_id);
        $page->call('closeSecret')->assertSet('secret', null)->assertSee('svk_…'.substr($plain, -4));

        $token = $app->apiTokens()->sole();
        $page->call('revoke', $token->id);
        $this->assertNull(LaravelApiToken::findPlain($plain));
        $page->call('unrevoke', $token->id);
        $this->assertNotNull(LaravelApiToken::findPlain($plain));
    }
}
