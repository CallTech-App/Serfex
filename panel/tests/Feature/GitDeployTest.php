<?php

namespace Tests\Feature;

use App\Enums\GitDeploymentStatus;
use App\Jobs\DeploySiteFromGit;
use App\Livewire\Sites\SiteGit;
use App\Models\Customer;
use App\Models\GitDeployment;
use App\Models\GitRepository;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Sites\GitDeploys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Git dağıtımı of sites that are not Laravel applications: a repository
 * is connected, its branch deployed into the site's folder by the agent,
 * earlier deploys returned to and the first one undone.
 */
class GitDeployTest extends TestCase
{
    use RefreshDatabase;

    private const C1 = '1a9e1bc3f0c5d2e4a7b8c9d0e1f2a3b4c5d6e7f8';

    private const C2 = '7d41c0e9b2a3f4e5d6c7b8a9f0e1d2c3b4a5f6e7';

    private FakeAgent $agent;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->agent = new FakeAgent([
            'site.git.key' => ['public_key' => 'ssh-ed25519 AAAAC3Nza serfex@kahve.com.tr'],
            'site.git.remove' => ['removed' => true],
            'site.git.read' => ['data' => "▸ Dağıtım başladı · main\n", 'offset' => 26, 'size' => 26],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->site = Site::factory()->create(['domain' => 'kahve.com.tr', 'php_version' => '8.4']);
        $this->site->systemUser->update(['name' => 'kahve']);
        $this->actingAs(User::factory()->create(['name' => 'Sercan']));
    }

    private function repo(array $values = []): GitRepository
    {
        return GitRepository::create(['site_id' => $this->site->id, 'repository' => 'https://github.com/kahve/site.git', ...$values]);
    }

    private function deployed(GitRepository $repo, int $number, string $commit, array $values = []): GitDeployment
    {
        return GitDeployment::create([
            'git_repository_id' => $repo->id, 'number' => $number, 'kind' => 'deploy', 'status' => GitDeploymentStatus::Succeeded,
            'branch' => 'main', 'commit' => $commit, 'message' => "Sürüm {$number}", 'run' => "git-run{$number}",
            'started_at' => now()->subMinutes(10 - $number), 'finished_at' => now()->subMinutes(10 - $number)->addSeconds(4), ...$values,
        ]);
    }

    public function test_the_site_page_links_git_deploys_instead_of_coming_soon(): void
    {
        $this->get(route('sites.show', $this->site))->assertOk()->assertSee(route('sites.git', $this->site))->assertSee('Bağlı değil');
        $this->repo();
        $this->get(route('sites.show', $this->site))->assertOk()->assertSee('kahve/site');
        $this->get(route('sites.git', $this->site))->assertOk()->assertSee('Git dağıtımı')->assertSee('kahve/site')->assertSee('Henüz dağıtılmadı');
    }

    public function test_a_laravel_site_is_sent_to_its_deployments(): void
    {
        LaravelApp::factory()->for($this->site)->create();
        $this->get(route('sites.git', $this->site))->assertOk()->assertSee('Bu sitede bir Laravel uygulaması var.')->assertDontSee('Depo bağla');
    }

    public function test_connecting_a_public_repository_and_deploying(): void
    {
        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->set('repository', 'https://github.com/kahve/site.git')
            ->set('branch', 'production')
            ->call('connect')
            ->assertHasNoErrors()
            ->assertDispatched('toast')
            ->call('deploy');

        $repo = GitRepository::sole();
        $this->assertSame(['production', 'public_html', null], [$repo->branch, $repo->path, $repo->token]);
        $this->assertSame(40, strlen($repo->webhook_token));
        $deployment = $repo->deployments()->sole();
        $this->assertSame([1, 'deploy', 'production', 'Sercan'], [$deployment->number, $deployment->kind, $deployment->branch, $deployment->triggered_by]);
        Queue::assertPushed(DeploySiteFromGit::class);

        // The agent checks the commit out; the result is recorded.
        $this->agent->answers['site.git.deploy'] = ['commit' => self::C1, 'message' => 'Ana sayfa', 'author' => 'Ada', 'previous' => '', 'changed' => 12, 'saved' => 1];
        (new DeploySiteFromGit($deployment))->handle($this->agent);
        $spec = $this->agent->called('site.git.deploy')[0];
        $this->assertSame(['kahve.com.tr', 'kahve', '8.4', 'https://github.com/kahve/site.git', 'production', 'public_html', '', '', false],
            [$spec['domain'], $spec['user'], $spec['php_version'], $spec['repository'], $spec['branch'], $spec['path'], $spec['token'], $spec['commit'], $spec['undo']]);
        $deployment->refresh();
        $this->assertSame([GitDeploymentStatus::Succeeded, self::C1, 'Ana sayfa', 12, 1], [$deployment->status, $deployment->commit, $deployment->message, $deployment->changed_files, $deployment->saved_files]);
        $this->assertSame(self::C1, $repo->fresh()->live_commit);

        $this->get(route('sites.git', $this->site))->assertSee('1a9e1bc')->assertSee('yayında')->assertSee('1 mevcut dosya saklandı')->assertSee('İlk dağıtımı geri al');
    }

    public function test_a_private_repository_gets_a_deploy_key_or_keeps_its_token_secret(): void
    {
        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->set('repository', 'git@github.com:kahve/site.git')
            ->call('connect')
            ->assertSet('showingKey', true)
            ->assertSet('publicKey', 'ssh-ed25519 AAAAC3Nza serfex@kahve.com.tr')
            ->assertSee('https://github.com/kahve/site/settings/keys');
        $this->assertSame([['domain' => 'kahve.com.tr', 'user' => 'kahve', 'php_version' => '8.4']], $this->agent->called('site.git.key'));
        GitRepository::sole()->delete();

        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->set('repository', 'https://gitlab.com/kahve/site.git')
            ->set('token', 'glpat-gizli')
            ->call('connect')
            ->assertSet('token', '');
        $repo = GitRepository::sole();
        $this->assertSame('glpat-gizli', $repo->token);
        $this->assertStringNotContainsString('glpat-gizli', (string) \DB::table('git_repositories')->value('token'));
        $this->assertArrayNotHasKey('token', $repo->toArray());

        // Settings keep the saved token unless a new one is typed.
        Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->call('openSettings')->assertSet('token', '')
            ->set('commands', 'composer install --no-dev')
            ->call('saveSettings')->assertHasNoErrors();
        $this->assertSame(['glpat-gizli', 'composer install --no-dev'], [$repo->fresh()->token, $repo->fresh()->commands]);
    }

    public function test_bad_addresses_and_folders_are_refused(): void
    {
        foreach (['https://u:p@github.com/k/s.git', 'ftp://example.com/s', 'kahve/site'] as $address) {
            Livewire::test(SiteGit::class, ['site' => $this->site->id])->set('repository', $address)->call('connect')->assertHasErrors('repository');
        }
        foreach (['../evil', '.serfex', 'laravel/current', 'public_html/../..'] as $path) {
            Livewire::test(SiteGit::class, ['site' => $this->site->id])->set('repository', 'https://github.com/k/s.git')->set('path', $path)->call('connect')->assertHasErrors('path');
        }
        $this->assertSame(0, GitRepository::count());
    }

    public function test_returning_to_an_earlier_deploy_can_be_undone(): void
    {
        $repo = $this->repo(['live_commit' => self::C2]);
        $first = $this->deployed($repo, 1, self::C1);
        $second = $this->deployed($repo, 2, self::C2, ['previous_commit' => self::C1]);

        $page = Livewire::test(SiteGit::class, ['site' => $this->site->id])
            ->assertSee('Bu sürüme dön')
            ->call('confirmRollback', $first->id)
            ->call('rollback');
        $rollback = $repo->deployments()->first();
        $this->assertSame(['rollback', self::C1, 3], [$rollback->kind, $rollback->commit, $rollback->number]);

        // Undo before it ran: it does not run.
        $page->call('undoRollback', $rollback->id, $second->id);
        $this->assertNull(GitDeployment::find($rollback->id));

        $this->agent->answers['site.git.deploy'] = ['commit' => self::C1, 'previous' => self::C2, 'changed' => 3];
        $rollback = app(GitDeploys::class)->rollback($repo, $first);
        (new DeploySiteFromGit($rollback))->handle($this->agent);
        $this->assertSame(self::C1, $this->agent->called('site.git.deploy')[0]['commit']);
        $this->assertSame(self::C1, $repo->fresh()->live_commit);
        $this->assertNull($rollback->fresh()->saved_files);
    }

    public function test_undoing_the_first_deploy(): void
    {
        $repo = $this->repo(['live_commit' => self::C1]);
        $this->deployed($repo, 1, self::C1, ['saved_files' => 2]);

        Livewire::test(SiteGit::class, ['site' => $this->site->id])->call('undoFirst');
        $undo = $repo->deployments()->first();
        $this->assertSame('undo', $undo->kind);

        $this->agent->answers['site.git.deploy'] = ['commit' => '', 'previous' => self::C1, 'changed' => 12];
        (new DeploySiteFromGit($undo))->handle($this->agent);
        $spec = $this->agent->called('site.git.deploy')[0];
        $this->assertTrue($spec['undo']);
        $this->assertSame('', $spec['commands']);
        $this->assertNull($repo->fresh()->live_commit);
        $this->get(route('sites.git', $this->site))->assertSee('İlk dağıtım geri alındı')->assertDontSee('wire:click="$set(\'undoing\', true)"', false);
    }

    public function test_failed_commands_leave_the_files_live_and_agent_errors_fail_the_deploy(): void
    {
        $repo = $this->repo(['commands' => 'composer install']);
        $deployment = app(GitDeploys::class)->deploy($repo);
        $this->agent->answers['site.git.deploy'] = ['commit' => self::C1, 'previous' => '', 'changed' => 4, 'saved' => 0, 'problem' => 'the commands failed with exit code 1'];
        (new DeploySiteFromGit($deployment))->handle($this->agent);
        $this->assertSame(GitDeploymentStatus::CommandsFailed, $deployment->fresh()->status);
        $this->assertSame('composer install', $this->agent->called('site.git.deploy')[0]['commands']);
        $this->assertSame(self::C1, $repo->fresh()->live_commit);

        $deployment = app(GitDeploys::class)->deploy($repo);
        $this->agent->answers['site.git.deploy'] = AgentException::rpcError('site.git.deploy', AgentException::OPERATION_FAILED, 'git fetch origin main failed with exit code 128');
        (new DeploySiteFromGit($deployment))->handle($this->agent);
        $this->assertSame([GitDeploymentStatus::Failed, 'git fetch origin main failed with exit code 128'], [$deployment->fresh()->status, $deployment->fresh()->error]);
        $this->assertSame(self::C1, $repo->fresh()->live_commit);
    }

    public function test_the_webhook_deploys_pushes_to_the_branch(): void
    {
        $repo = $this->repo();
        $url = route('laravel.webhook', $repo->webhook_token);

        $this->postJson($url, ['ref' => 'refs/heads/main'], ['X-GitHub-Event' => 'push'])->assertStatus(202)->assertJson(['message' => 'Automatic deploys are off for this site.']);
        $repo->update(['auto_deploy' => true]);
        $this->postJson($url, ['ref' => 'refs/heads/dev'], ['X-GitHub-Event' => 'push'])->assertStatus(202)->assertJsonFragment(['message' => 'Push to dev ignored; the site deploys main.']);
        $this->postJson($url, ['ref' => 'refs/heads/main'], ['X-GitHub-Event' => 'push'])->assertStatus(202)->assertJson(['deployment' => 1]);
        $this->assertSame('GitHub webhook', $repo->deployments()->sole()->triggered_by);
        $this->postJson($url, [])->assertStatus(409);
        $this->postJson(route('laravel.webhook', str_repeat('x', 40)), [])->assertNotFound();
    }

    public function test_disconnecting_keeps_the_files_and_forgets_the_repository(): void
    {
        $repo = $this->repo();
        $this->deployed($repo, 1, self::C1);
        Livewire::test(SiteGit::class, ['site' => $this->site->id])->call('disconnect')->assertSee('Depo bağla');
        $this->assertSame(1, count($this->agent->called('site.git.remove')));
        $this->assertSame(0, GitRepository::count() + GitDeployment::count());
    }

    public function test_customers_use_git_on_their_own_sites_only(): void
    {
        $customer = Customer::factory()->withLogin()->create(['username' => 'ada']);
        $own = Site::factory()->for(app(Customers::class)->account($customer))->create(['domain' => 'adapansiyon.com']);
        $this->actingAs($customer->user);
        $this->get(route('sites.git', $own))->assertOk()->assertSee('Depo bağla');
        $this->get(route('sites.git', $this->site))->assertNotFound();
    }
}
