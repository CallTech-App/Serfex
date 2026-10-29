<?php

namespace Tests\Feature;

use App\Jobs\RunComponentOperation;
use App\Livewire\Components\ComponentIndex;
use App\Models\ComponentOperation;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Components\Components;
use App\Services\Components\SystemPackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Yazılım Yönetimi → Ubuntu güncellemeleri: Ubuntu's packages with
 * updates, upgrading all or a few in the background, the restart a new
 * kernel asks for, the automatic security updates and the dashboard.
 */
class SystemPackagesTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    /** @var array<string, mixed> packages.list */
    private array $packages;

    /** @var array<string, mixed> component.list */
    private array $components = ['components' => [
        ['id' => 'nginx', 'installed' => true, 'version' => '1.24.0-2ubuntu7.5', 'running' => true, 'units' => ['nginx']],
    ]];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->packages = [
            'packages' => [
                ['name' => 'curl', 'installed' => '8.5.0-2ubuntu10.6', 'version' => '8.5.0-2ubuntu10.7', 'origin' => 'Ubuntu', 'security' => false],
                ['name' => 'libssl3t64', 'installed' => '3.0.13-0ubuntu3.5', 'version' => '3.0.13-0ubuntu3.6', 'origin' => 'Ubuntu', 'security' => true],
                ['name' => 'linux-image-6.8.0-90-generic', 'version' => '6.8.0-90.91', 'origin' => 'Ubuntu', 'security' => true, 'new' => true],
                ['name' => 'nginx', 'installed' => '1.24.0-2ubuntu7.5', 'version' => '1.24.0-2ubuntu7.6', 'origin' => 'Ubuntu', 'security' => false, 'component' => 'nginx'],
                ['name' => 'php8.4-fpm', 'installed' => '8.4.13-1+ubuntu24.04.1+deb.sury.org+1', 'version' => '8.4.14-1+ubuntu24.04.1+deb.sury.org+1', 'origin' => 'LP-PPA-ondrej-php', 'security' => false, 'component' => 'php8.4'],
                ['name' => 'cloud-init', 'installed' => '25.1.4', 'version' => '25.2', 'origin' => 'archive.ubuntu.com/ubuntu', 'security' => false, 'held' => true],
                ['name' => 'python3-distupgrade', 'installed' => '1:24.04.26', 'version' => '1:24.04.27', 'origin' => 'archive.ubuntu.com/ubuntu', 'security' => false, 'phased' => true],
                ['name' => 'ubuntu-drivers-common', 'installed' => '1:0.9.7.6ubuntu3.2', 'version' => '1:0.9.7.6ubuntu3.4', 'origin' => 'archive.ubuntu.com/ubuntu', 'security' => false, 'kept_back' => true],
            ],
            'checked_at' => now()->subHours(2)->toIso8601String(),
            'reboot' => ['required' => false],
            'auto' => ['installed' => true, 'enabled' => true, 'security_only' => true, 'reboot' => false, 'last_run' => now()->subHours(5)->toIso8601String()],
        ];
        $this->agent = new FakeAgent([
            'component.list' => fn () => $this->components,
            'component.check' => ['components' => []],
            'packages.list' => fn () => $this->packages,
            'packages.upgrade' => fn () => $this->packages,
            'packages.auto' => fn ($p) => ['enabled' => $p['enabled']],
            'system.reboot' => ['required' => true],
            'component.run.read' => ['data' => "▸ Paketler güncelleniyor: 3 paket\n✓ Güncellendi\n", 'offset' => 50, 'size' => 50, 'done' => true],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    public function test_lists_ubuntu_updates_security_first(): void
    {
        $this->get('/software?tab=packages')->assertOk()
            ->assertSee('Ubuntu güncellemeleri')
            ->assertSeeInOrder(['libssl3t64', 'linux-image-6.8.0-90-generic', 'cloud-init', 'curl'])
            ->assertSee('3.0.13-0ubuntu3.5')
            ->assertSee('Güvenlik')
            ->assertSee('Yeni paket')
            ->assertSee('apt-mark hold ile bekletiliyor; Serfex güncellemez.')
            ->assertSee('Ubuntu bu güncellemeyi sunuculara sırayla dağıtıyor; sıra gelince seçilebilir.')
            ->assertSee('ppa:ondrej/php')
            ->assertSee('PHP 8.4')
            // curl, libssl, nginx, php8.4-fpm; ubuntu-drivers-common is kept back.
            ->assertSee('Tümünü güncelle (4)')
            ->assertSee('Tümü (8)')
            ->assertSee('Güvenlik (2)')
            ->assertSee('Otomatik güvenlik güncellemeleri')
            ->assertSee('Ubuntu her gün güvenlik güncellemelerini kendiliğinden kurar (unattended-upgrades).')
            ->assertDontSee('Sunucunun yeniden başlatılması gerekiyor');

        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->set('packageFilter', 'security')
            ->assertSee('libssl3t64')
            ->assertDontSee('curl');
    }

    public function test_updates_everything_in_the_background(): void
    {
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->call('askPackages', 'all')
            ->assertSee('4 Ubuntu paketi güncellensin mi?')
            ->assertSee('Hiçbir paket kaldırılmaz')
            ->assertSee('1 yeni paket gelir')
            ->assertSee('Nginx, PHP 8.4')
            ->call('confirm')
            ->assertSet('refused', null)
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Ubuntu paketlerinin hepsi güncelleniyor.')
            ->assertSee('Ubuntu paketleri güncelleniyor');

        $op = ComponentOperation::sole();
        $this->assertSame([SystemPackages::COMPONENT, SystemPackages::ACTION, null], [$op->component, $op->action, $op->packages]);
        Queue::assertPushed(RunComponentOperation::class);

        (new RunComponentOperation($op))->handle($this->agent, app(Components::class));
        $this->assertSame([['run' => $op->run, 'packages' => []]], $this->agent->called('packages.upgrade'));
        $this->assertSame('succeeded', $op->fresh()->status);

        // One at a time.
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])->call('askPackages', 'one', 'curl')->call('confirm')->assertSet('refused', null);
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])->call('askPackages', 'all')->call('confirm')
            ->assertSet('refused', 'Başka bir kurulum sürüyor. Bitince yeniden deneyin.');
    }

    public function test_updates_chosen_packages_and_refuses_the_others(): void
    {
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->set('selected', ['curl', 'ubuntu-drivers-common'])
            ->assertSee('Seçilenleri güncelle (2)')
            ->call('askPackages', 'selected')
            ->assertSee('2 Ubuntu paketi güncellensin mi?')
            ->assertSee('curl, ubuntu-drivers-common')
            ->call('confirm')
            ->assertSet('refused', null)
            ->assertSet('selected', []);
        $op = ComponentOperation::sole();
        $this->assertSame(['curl', 'ubuntu-drivers-common'], $op->packages);
        (new RunComponentOperation($op))->handle($this->agent, app(Components::class));
        $this->assertSame(['curl', 'ubuntu-drivers-common'], $this->agent->called('packages.upgrade')[0]['packages']);
        $op->delete();

        $packages = app(SystemPackages::class);
        $admin = auth()->user();
        $this->assertSame('cloud-init tek başına güncellenemez.', $packages->start(['cloud-init'], $admin));
        $this->assertSame('python3-distupgrade tek başına güncellenemez.', $packages->start(['python3-distupgrade'], $admin));
        $this->assertSame('linux-image-6.8.0-90-generic tek başına güncellenemez.', $packages->start(['linux-image-6.8.0-90-generic'], $admin));
        $this->assertSame('bash için güncelleme yok.', $packages->start(['bash'], $admin));
        $this->assertSame(0, ComponentOperation::count());

        // Ticking all takes only what can be chosen.
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->call('selectAllPackages', true)
            ->assertSet('selected', ['libssl3t64', 'curl', 'nginx', 'php8.4-fpm', 'ubuntu-drivers-common']);
    }

    public function test_only_full_administrators_update(): void
    {
        $this->actingAs(User::factory()->create(['is_admin' => true, 'role' => 'support']));
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->assertSee('Güncellemeleri yalnızca tam yetkili yöneticiler başlatabilir.')
            ->call('askPackages', 'all')->call('confirm')
            ->assertSet('refused', 'Ubuntu paketlerini yalnızca tam yetkili yöneticiler güncelleyebilir.')
            ->call('setAutoUpdates', false)
            ->assertSet('refused', 'Otomatik güncellemeleri yalnızca tam yetkili yöneticiler değiştirebilir.')
            ->call('askReboot')->call('confirm')
            ->assertSet('refused', 'Sunucuyu yalnızca tam yetkili yöneticiler yeniden başlatabilir.');
        $this->assertSame(0, ComponentOperation::count());
        $this->assertSame([], $this->agent->called('packages.auto'));
        $this->assertSame([], $this->agent->called('system.reboot'));
    }

    public function test_restart_when_an_upgrade_asks_for_it(): void
    {
        $this->packages['reboot'] = ['required' => true, 'since' => now()->subHours(3)->toIso8601String(), 'packages' => ['linux-image-6.8.0-90-generic', 'libssl3t64']];

        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->assertSee('Sunucunun yeniden başlatılması gerekiyor')
            ->assertSee('linux-image-6.8.0-90-generic, libssl3t64')
            ->call('askReboot')
            ->assertSee('Sunucu yeniden başlatılsın mı?')
            ->call('confirm')
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Sunucu 1 dakika içinde yeniden başlatılacak.' && $p['undo']['method'] === 'abortReboot')
            ->call('abortReboot')
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Sunucunun yeniden başlatılması iptal edildi.');
        $this->assertSame([[], ['cancel' => true]], $this->agent->called('system.reboot'));

        $this->packages['reboot']['scheduled'] = now()->addMinute()->toIso8601String();
        app(SystemPackages::class)->forget();
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->assertSee('yeniden başlayacak')
            ->assertSee('Yeniden başlatmayı iptal et');
    }

    public function test_turns_automatic_security_updates_off_with_undo(): void
    {
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->call('setAutoUpdates', false)
            ->assertDispatched('toast', fn ($name, $p) => $p['message'] === 'Güvenlik güncellemeleri artık kendiliğinden kurulmayacak.' && $p['undo']['args'] === [true]);
        $this->assertSame([['enabled' => false]], $this->agent->called('packages.auto'));

        $this->packages['auto'] = ['installed' => true, 'enabled' => false];
        Livewire::test(ComponentIndex::class, ['tab' => 'packages'])
            ->assertSee('Güvenlik güncellemeleri ancak buradan kurulunca gelir.')
            ->assertSee('Kapalı');
    }

    public function test_upgrade_is_closed_only_once_the_agent_is_done(): void
    {
        $op = ComponentOperation::create(['component' => SystemPackages::COMPONENT, 'action' => SystemPackages::ACTION, 'run' => 'pabc', 'status' => 'running']);
        $this->travel(20)->minutes();
        $this->components['packages_busy'] = 'upgrade';
        app(Components::class)->statuses();
        $this->assertSame('running', $op->fresh()->status);

        unset($this->components['packages_busy']);
        app(Components::class)->statuses();
        $this->assertSame('succeeded', $op->fresh()->status);
    }

    public function test_dashboard_shows_waiting_security_updates_and_restarts(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Güncellemeler')
            ->assertSee('1 güvenlik güncellemesi')
            ->assertSee('1 Ubuntu güvenlik güncellemesi bekliyor')
            ->assertSee('Ubuntu güncellemelerine git');

        $this->packages['reboot'] = ['required' => true, 'packages' => ['linux-image-6.8.0-90-generic']];
        app(SystemPackages::class)->forget();
        $this->get(route('dashboard'))->assertOk()
            ->assertSee('Sunucunun yeniden başlatılması gerekiyor ve 1 güvenlik güncellemesi bekliyor')
            ->assertSee('linux-image-6.8.0-90-generic');

        $this->packages['reboot'] = ['required' => false];
        $this->packages['packages'] = [];
        app(SystemPackages::class)->forget();
        $this->get(route('dashboard'))->assertOk()->assertSee('Ubuntu güncel');
    }
}
