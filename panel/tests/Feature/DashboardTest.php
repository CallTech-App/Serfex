<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\BackupTarget;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAgent;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_renders_when_agent_is_down(): void
    {
        config(['serfex.agent.socket' => sys_get_temp_dir().'/serfex-missing.sock']);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('Genel Bakış')
            ->assertSee('Sunucu ajanına ulaşılamıyor')
            ->assertSee('Yeniden dene')
            ->assertDontSee('Sunucu kaynakları');
    }

    public function test_a_quiet_server_shows_its_checks_resources_and_busiest_sites(): void
    {
        $this->fakeAgent();
        Site::factory()->for(SystemUser::factory()->create(['name' => 'blog']), 'systemUser')->create(['domain' => 'gunluk.net']);
        $admin = User::factory()->create(['two_factor_secret' => 'JBSWY3DPEHPK3PXP', 'two_factor_confirmed_at' => now()]);
        AuditEntry::create(['user_id' => $admin->id, 'actor' => $admin->name, 'via' => 'panel', 'message' => 'Panele giriş yaptı.', 'created_at' => now()]);
        AuditEntry::create(['user_id' => $admin->id, 'actor' => $admin->name, 'via' => 'panel', 'message' => 'gunluk.net sitesi oluşturuldu', 'created_at' => now()]);

        $this->actingAs($admin)
            ->get('/')
            ->assertOk()
            ->assertSee('Her şey yolunda')
            ->assertSeeInOrder(['Canlı izleme', 'Hizmetler', 'SSL sertifikaları', 'Yedekler', 'Güvenlik taraması', 'Disk alanı', 'Müşteri paketleri', 'Hesabınız', 'Güncellemeler'])
            ->assertSee('Sunucu kaynakları')
            ->assertSee('%30')
            ->assertSee('Barındırma')
            ->assertSee('gunluk.net')
            ->assertSee('Nginx')
            ->assertSee('gunluk.net sitesi oluşturuldu')
            ->assertDontSee('Panele giriş yaptı.');
    }

    public function test_problems_are_listed_under_the_checks(): void
    {
        $this->fakeAgent();
        BackupTarget::create(['name' => 'Storage Box', 'type' => 'sftp', 'config' => [], 'status' => 'failed', 'last_error' => 'Connection timed out']);

        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('1 konu ilgilenmenizi bekliyor')
            ->assertSee('Yedeklemede sorun var')
            ->assertSee('Storage Box hedefine ulaşılamıyor: Connection timed out')
            ->assertSee('Yedeklere git')
            ->assertSee('İki adımlı doğrulama kapalı');
    }

    public function test_activity_is_only_for_full_administrators(): void
    {
        $this->fakeAgent();
        AuditEntry::create(['actor' => 'Sistem', 'via' => 'system', 'message' => 'Gece yedeği tamamlandı', 'created_at' => now()]);

        $this->actingAs(User::factory()->create(['role' => 'readonly']))
            ->get('/')
            ->assertOk()
            ->assertDontSee('Son etkinlik')
            ->assertDontSee('Gece yedeği tamamlandı')
            ->assertDontSee('Site ekle');
    }

    private function fakeAgent(): void
    {
        // The test machine's own disk would add a check result of its own.
        config(['serfex.disk_paths' => []]);
        $this->app->instance(AgentClient::class, new FakeAgent([
            'monitor.live' => [
                'at' => now()->toIso8601String(), 'window' => 60, 'interval' => 2,
                'server' => ['cpus' => 4, 'memory_total' => 8 << 30, 'load' => [0.5, 0.4, 0.3], 'uptime' => 90000, 'cpu' => 30, 'memory' => 3 << 30, 'net_rx' => 1000, 'net_tx' => 5000],
                'sites' => [[
                    'domain' => 'gunluk.net', 'user' => 'blog', 'cpu' => 12.0, 'memory' => 100 << 20, 'io' => 0, 'processes' => 2,
                    'php' => ['known' => true, 'busy' => 1, 'workers' => 2, 'max' => 10, 'queue' => 0, 'max_reached' => false],
                    'requests' => 2.5, 'client_errors' => 0, 'server_errors' => 0,
                    'db' => ['connections' => 0, 'active' => 0, 'longest' => 0], 'mail_per_hour' => 0, 'top_ips' => [], 'limit' => null,
                ]],
                'accounts' => [],
                'services' => [['unit' => 'nginx.service', 'name' => 'Nginx', 'group' => 'shared', 'cpu' => 5, 'memory' => 64 << 20, 'io' => 0, 'processes' => 5]],
                'suspects' => [],
                'databases' => [],
            ],
        ]));
    }
}
