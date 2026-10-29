<?php

namespace App\Livewire\Dashboard;

use App\Models\AuditEntry;
use App\Models\Backup;
use App\Models\Certificate;
use App\Models\Customer;
use App\Models\Database;
use App\Models\DnsZone;
use App\Models\IpBlock;
use App\Models\LaravelApp;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\Package;
use App\Models\ResourceLimit;
use App\Models\SecurityScan;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Ai\AiSettings;
use App\Services\Backups\Backups;
use App\Services\Components\SystemPackages;
use App\Services\Customers\DiskQuotas;
use App\Services\Customers\Quotas;
use App\Services\Monitor\Health;
use App\Services\Monitor\LiveStats;
use App\Services\Security\Scans;
use App\Services\Stats\ServerHistory;
use App\Services\Stats\SiteStats;
use App\Services\System\SystemServices;
use App\Services\Updates\Updates;
use App\Support\Bytes;
use App\Support\Num;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Genel Bakış for administrators: the server's checks and what needs a
 * look first, then live resources, what the server hosts, the busiest
 * sites, Laravel deployments and recent activity.
 */
class Overview extends Component
{
    /** Seconds the live numbers average over. */
    private const WINDOW = 60;

    public function render(AgentClient $agent, LiveStats $stats): View
    {
        $user = auth()->user();
        $online = $agent->ping();
        $snap = null;
        if ($online) {
            try {
                $snap = $stats->snapshot(self::WINDOW);
                LiveStats::rememberAttention($snap);
            } catch (AgentException) {
                $snap = null;
            }
        }

        $day = $this->history('24h');
        $daySum = ServerHistory::summary($day);
        $checks = $this->checks($snap, $user, $online);
        $problems = array_values(array_filter($checks, fn ($c) => $c['problem'] !== null));
        if (! $online) {
            array_unshift($problems, ['tone' => 'crit', 'icon' => 'unplug', 'problem' => [
                'title' => __('Sunucu ajanına ulaşılamıyor'),
                'text' => __('Panel sunucuda değişiklik yapamıyor ve canlı değerleri okuyamıyor. Sunucuda "systemctl status serfex-agent" komutuyla durumuna bakın.'),
                'ai' => __('Panel sunucu ajanına ulaşamıyor. Neden olabilir, nasıl düzeltirim?'),
                'refresh' => true,
            ]]);
        }

        return view('livewire.dashboard.overview', [
            'online' => $online,
            'server' => $this->server($snap),
            'checks' => $checks,
            'problems' => $problems,
            'okCount' => count(array_filter($checks, fn ($c) => ! in_array($c['tone'], ['warn', 'crit'], true))),
            'ai' => $this->ai(),
            'resources' => $snap ? $this->resources($snap, $day, $daySum) : null,
            'inventory' => $this->inventory(),
            'sites' => $snap ? $this->busiestSites($snap) : null,
            'services' => $snap ? $this->services($snap) : null,
            'apps' => $this->apps(),
            // Sign-ins are left out: they would push the changes off the list.
            'activity' => $user->isFullAdmin() ? AuditEntry::whereNotIn('message', ['Panele giriş yaptı.', trans('Panele giriş yaptı.', [], 'en')])
                ->latest('created_at')->latest('id')->limit(6)->get() : null,
        ]);
    }

    /**
     * Server history points of a range, cached for a minute like Canlı İzleme.
     *
     * @return list<array<string, mixed>>
     */
    private function history(string $span): array
    {
        $cached = Cache::remember('serfex.server-history.'.$span, 60, fn () => array_map(
            fn (array $p) => ['at' => $p['at']->getTimestamp()] + $p,
            app(ServerHistory::class)->series($span),
        ));

        return array_map(fn (array $p) => ['at' => CarbonImmutable::createFromTimestamp($p['at'], config('serfex.timezone'))] + $p, $cached);
    }

    /**
     * The line under the title: host name, system, size and uptime.
     *
     * @return array{name: string, os: ?string, cpus: ?int, memory: ?int, uptime: ?int}
     */
    private function server(?array $snap): array
    {
        $os = null;
        $release = @file_get_contents('/etc/os-release');
        if ($release !== false && preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', $release, $m)) {
            $os = $m[1];
        }

        return [
            'name' => (string) (Setting::get('server.hostname') ?: gethostname() ?: 'localhost'),
            'os' => $os,
            'cpus' => $snap['server']['cpus'] ?? null,
            'memory' => $snap['server']['memory_total'] ?? null,
            'uptime' => $snap['server']['uptime'] ?? null,
        ];
    }

    /**
     * Each check: its tone (ok, warn, crit, info or muted), a short state
     * for the grid, where it leads and, when it needs a look, the line
     * for the list under the grid.
     *
     * @return list<array<string, mixed>>
     */
    private function checks(?array $snap, $user, bool $online = true): array
    {
        $checks = [];
        $add = function (string $icon, string $name, string $tone, string $text, ?string $url, ?array $problem = null) use (&$checks) {
            $checks[] = compact('icon', 'name', 'tone', 'text', 'url', 'problem');
        };

        // Canlı izleme
        $attention = (int) Cache::get(LiveStats::ATTENTION_KEY, 0);
        if ($attention > 0) {
            $attacked = $snap ? array_values(array_unique(array_filter(array_column(LiveStats::openSuspects($snap), 'target')))) : [];
            $struggling = [];
            foreach ($snap['sites'] ?? [] as $u) {
                if (Health::site($u, 0, false)[1] <= 2 && ! in_array($u['domain'], $attacked, true)) {
                    $struggling[] = $u['domain'];
                }
            }
            $names = array_slice(array_merge($attacked, $struggling), 0, 3);
            $add('activity', __('Canlı izleme'), $attacked ? 'crit' : 'warn', trans_choice(':count konu', $attention), route('monitor.index'), [
                'title' => trans_choice(':count site veya saldırı ilgilenmenizi bekliyor', $attention),
                'text' => $attacked
                    ? trans_choice('Saldırı altındaki site: :domains', count($attacked), ['domains' => implode(', ', array_slice($attacked, 0, 3))])
                    : ($names ? __('Yüksek kaynak kullanan ya da hata veren: :domains', ['domains' => implode(', ', $names)]) : __('Ayrıntılar Canlı İzleme sayfasında.')),
                'ai' => __('Canlı İzleme\'de ilgilenmem gereken siteler ya da saldırılar var. Neler oluyor, ne yapmalıyım?'),
                'action' => [__('Canlı izlemede aç'), route('monitor.index')],
            ]);
        } else {
            $add('activity', __('Canlı izleme'), 'ok', __('Sorun yok'), route('monitor.index'));
        }

        // Hizmetler
        $down = SystemServices::problems();
        if ($down) {
            $names = collect($down)->pluck('name')->join(', ');
            $url = count($down) === 1 ? route('services.show', $down[0]['unit']) : route('services.index');
            $title = count($down) === 1 ? __(':name çalışmıyor', ['name' => $names]) : __(':count hizmet çalışmıyor: :names', ['count' => count($down), 'names' => $names]);
            $add('server-cog', __('Hizmetler'), 'crit', trans_choice(':count hizmet çalışmıyor', count($down)), $url, [
                'title' => $title,
                'text' => __('Bu hizmeti kullanan siteler ve posta etkilenebilir. Hizmetin sayfasında günlüğüne bakıp yeniden başlatabilirsiniz.'),
                'ai' => __('Sunucuda çalışmayan hizmetler var: :service_names. Neden durmuş olabilir, nasıl düzeltirim?', ['service_names' => $names]),
                'action' => [__('Hizmete git'), $url],
            ]);
        } else {
            $add('server-cog', __('Hizmetler'), SystemServices::checked() ? 'ok' : 'muted', SystemServices::checked() ? __('Tüm hizmetler çalışıyor') : __('Henüz denetlenmedi'), route('services.index'));
        }

        // SSL
        $ssl = Certificate::attentionCount();
        $sslUrl = route('ssl.index', $ssl ? ['status' => 'problem'] : []);
        $add('shield-check', __('SSL sertifikaları'), $ssl ? 'warn' : 'ok', $ssl ? trans_choice(':count sertifika ilgilenmenizi bekliyor', $ssl) : __('Sorun yok'), $sslUrl, $ssl ? [
            'title' => trans_choice(':count sertifika ilgilenmenizi bekliyor', $ssl),
            'text' => __('Süresi dolmak üzere olan ya da yenilenemeyen sertifikalar var. Süre dolarsa ziyaretçiler güvenlik uyarısı görür.'),
            'ai' => __('İlgilenmem gereken SSL sertifikaları var. Hangileri, neden ve nasıl düzeltirim?'),
            'action' => [__('Sertifikaları aç'), $sslUrl],
        ] : null);

        // Yedekler
        $backupProblems = app(Backups::class)->problems();
        if ($backupProblems) {
            $first = $backupProblems[0];
            $add('archive', __('Yedekler'), $first['level'] === 'crit' ? 'crit' : 'warn', __('Yedeklemede sorun var'), route('backups.index'), [
                'title' => count($backupProblems) > 1 ? __(':count yedekleme sorunu', ['count' => count($backupProblems)]) : __('Yedeklemede sorun var'),
                'text' => $first['text'],
                'ai' => __('Yedeklemede sorun var: :text Neden olmuş olabilir, nasıl düzeltirim?', ['text' => $first['text']]),
                'action' => [__('Yedeklere git'), route('backups.index')],
            ]);
        } else {
            $last = Backup::where('scope', 'server')->usable()->latest('finished_at')->value('finished_at');
            $add('archive', __('Yedekler'), $last ? 'ok' : 'muted', $last ? __('Son sunucu yedeği :when', ['when' => Carbon::parse($last)->diffForHumans()]) : __('Henüz sunucu yedeği yok'), route('backups.index'));
        }

        // Güvenlik taraması
        $findings = Scans::attentionCount();
        $scanUrl = route('security.index', ['tab' => 'scan']);
        if ($findings) {
            $add('shield-alert', __('Güvenlik taraması'), 'crit', trans_choice(':count ciddi güvenlik bulgusu var', $findings), $scanUrl, [
                'title' => trans_choice(':count ciddi güvenlik bulgusu var', $findings),
                'text' => __('Taramada zararlı dosya ya da açık bulundu. Bulguları inceleyip karantinaya alın.'),
                'ai' => __('Güvenlik taramasında ciddi bulgular var. Hangileri, ne kadar tehlikeli ve nasıl düzeltirim?'),
                'action' => [__('Bulguları aç'), $scanUrl],
            ]);
        } else {
            $lastScan = SecurityScan::where('scope', 'server')->whereNotNull('finished_at')->latest('id')->value('finished_at');
            $add('shield-alert', __('Güvenlik taraması'), $lastScan ? 'ok' : 'muted', $lastScan ? __('Ciddi bulgu yok · :when', ['when' => Carbon::parse($lastScan)->diffForHumans()]) : __('Henüz tarama yapılmadı'), $scanUrl);
        }

        // Disk alanı
        $disk = ServerHistory::disk();
        $diskUrl = route('monitor.index', ['tab' => 'system']);
        if ($disk === null) {
            $add('hard-drive', __('Disk alanı'), 'muted', __('Ölçülmedi'), $diskUrl);
        } else {
            [$used, $total] = $disk;
            $ratio = $used / $total;
            $tone = $ratio >= 0.97 ? 'crit' : ($ratio >= 0.9 ? 'warn' : 'ok');
            $free = Bytes::format(max(0, $total - $used));
            $add('hard-drive', __('Disk alanı'), $tone, __(':percent dolu · :free boş', ['percent' => Num::percent(round($ratio * 100)), 'free' => $free]), $diskUrl, $tone === 'ok' ? null : [
                'title' => __('Disk alanı azalıyor'),
                'text' => __('Yalnızca :free boş yer kaldı. Disk dolarsa siteler, veritabanları ve posta yazamaz hale gelir.', ['free' => $free]),
                'ai' => __('Sunucunun diski dolmak üzere. Yeri ne kaplıyor ve güvenle neyi temizleyebilirim?'),
                'action' => [__('Kaynaklara bak'), $diskUrl],
            ]);
        }

        // Müşteri paketleri
        $quota = app(Quotas::class)->attentionCount();
        $diskQuotas = app(DiskQuotas::class);
        $full = $diskQuotas->fullCustomers();
        $serverUrl = route('settings.server');
        if ($full->isNotEmpty()) {
            $url = $full->count() === 1 ? route('customers.show', $full->first()) : route('customers.index');
            $add('users', __('Müşteri paketleri'), 'crit', trans_choice(':count müşterinin diski dolu', $full->count()), $url, [
                'title' => $full->count() === 1
                    ? __(':name müşterisinin disk kotası doldu', ['name' => $full->first()->displayName()])
                    : trans_choice(':count müşterinin disk kotası doldu', $full->count()),
                'text' => $full->count() === 1
                    ? __('Müşterinin dosyalarına yazılamıyor (yükleme, FTP, siteler) ve gelen postası bekletiliyor. Yer açmasını isteyebilir ya da disk sınırını yükseltebilirsiniz.')
                    : __('Bu müşterilerin dosyalarına yazılamıyor (yükleme, FTP, siteler) ve gelen postaları bekletiliyor. Yer açmalarını isteyebilir ya da disk sınırlarını yükseltebilirsiniz.'),
                'ai' => null,
                'action' => [__('Müşteriye git'), $url],
            ]);
        } elseif ($diskQuotas->broken()) {
            $add('users', __('Müşteri paketleri'), 'warn', __('Disk kotaları çalışmıyor'), $serverUrl, [
                'title' => __('Disk kotaları çalışmıyor'),
                'text' => __('Dosya sistemi kotaları kapandı, bu yüzden müşteriler paketlerindeki disk alanını aşabilir. Serfex 5 dakikada bir yeniden açmayı dener.'),
                'ai' => null,
                'action' => [__('Sunucu ayarlarına git'), $serverUrl],
            ]);
        } else {
            $add('users', __('Müşteri paketleri'), $quota ? 'warn' : 'ok', $quota ? trans_choice(':count müşteri sınıra yakın', $quota) : trans_choice(':count müşteri', Customer::count()), route('customers.index'), $quota ? [
                'title' => trans_choice(':count müşteri paket sınırına yaklaştı ya da aştı', $quota),
                'text' => __('Sınırı aşan müşteri yeni site, posta kutusu ya da veritabanı açamaz. Paketini yükseltebilir ya da müşteriyle görüşebilirsiniz.'),
                'ai' => null,
                'action' => [__('Müşterilere git'), route('customers.index')],
            ] : null);
        }

        // Hesabınız
        $twoFactor = $user->hasTwoFactor();
        $add('key-round', __('Hesabınız'), $twoFactor ? 'ok' : 'info', $twoFactor ? __('İki adımlı doğrulama açık') : __('İki adımlı doğrulama kapalı'), route('account.security'));

        // Güncellemeler: Serfex itself, then Ubuntu's packages.
        $notices = app(Updates::class)->notices();
        $updatesUrl = $user->isFullAdmin() ? route('settings.updates') : null;
        $warn = collect($notices)->firstWhere('level', 'warn');
        $system = $online ? app(SystemPackages::class)->summary() : null;
        $systemUrl = route('components.index', ['tab' => 'packages']);
        if ($warn) {
            $add('refresh-cw', __('Güncellemeler'), 'warn', $notices[0]['text'], $updatesUrl, [
                'title' => $warn['text'],
                'text' => __('Panel çalışan sürümde kaldı. Ayrıntılar Güncellemeler sayfasında.'),
                'ai' => null,
                'action' => $updatesUrl ? [__('Güncellemelere git'), $updatesUrl] : null,
            ]);
        } elseif ($system !== null && ($system['reboot'] || $system['security'] > 0)) {
            $title = match (true) {
                $system['reboot'] && $system['security'] > 0 => trans_choice('Sunucunun yeniden başlatılması gerekiyor ve :count güvenlik güncellemesi bekliyor', $system['security']),
                $system['reboot'] => __('Sunucunun yeniden başlatılması gerekiyor'),
                default => trans_choice(':count Ubuntu güvenlik güncellemesi bekliyor', $system['security']),
            };
            $add('refresh-cw', __('Güncellemeler'), 'warn', $system['reboot'] ? __('Yeniden başlatma gerekiyor') : trans_choice(':count güvenlik güncellemesi', $system['security']), $systemUrl, [
                'title' => $title,
                'text' => $system['reboot']
                    ? __('Güncellenen paketler (:packages) ancak sunucu yeniden başlayınca devreye girer. Uygun bir saatte Yazılım Yönetimi → Ubuntu güncellemeleri sayfasından yeniden başlatın.', ['packages' => implode(', ', array_slice($system['reboot_packages'], 0, 3)) ?: __('çekirdek')])
                    : ($system['auto']
                        ? __('Ubuntu bunları her gün kendiliğinden kurar; hemen kurmak için Ubuntu güncellemeleri sayfasına gidin.')
                        : __('Otomatik güvenlik güncellemeleri kapalı, bu yüzden bunlar siz kurana kadar bekler.')),
                'ai' => null,
                'action' => [__('Ubuntu güncellemelerine git'), $systemUrl],
            ]);
        } elseif ($notices) {
            $add('refresh-cw', __('Güncellemeler'), 'info', $notices[0]['text'], $updatesUrl);
        } elseif ($system !== null && $system['updates'] > 0) {
            $add('refresh-cw', __('Güncellemeler'), 'info', trans_choice(':count Ubuntu güncellemesi var', $system['updates']), $systemUrl);
        } else {
            $add('refresh-cw', __('Güncellemeler'), 'ok', $system !== null ? __('Serfex :version · Ubuntu güncel', ['version' => config('serfex.version')]) : 'Serfex '.config('serfex.version'), $updatesUrl);
        }

        foreach ($checks as &$c) {
            if ($c['problem'] !== null) {
                $c['problem'] += ['refresh' => false, 'action' => null];
            }
        }

        return $checks;
    }

    /**
     * What Serfex AI did on its own lately, for the one AI line.
     *
     * @return array{enabled: bool, count: int, last: ?IpBlock}
     */
    private function ai(): array
    {
        $blocks = IpBlock::where('source', 'ai')->where('created_at', '>=', now()->subDay());

        return [
            'enabled' => app(AiSettings::class)->enabled(),
            'count' => (clone $blocks)->count(),
            'last' => $blocks->latest('id')->first(),
        ];
    }

    /**
     * The four resource tiles: now, with the last 24 hours as a sparkline.
     *
     * @return array<string, mixed>
     */
    private function resources(array $snap, array $day, array $daySum): array
    {
        $srv = $snap['server'];
        $disk = ServerHistory::disk();
        $daysLeft = null;
        if ($disk !== null) {
            $month = array_values(array_filter($this->history('30d'), fn ($p) => $p['disk_used'] !== null));
            if (count($month) > 1) {
                $first = $month[0];
                $last = end($month);
                $days = $first['at']->diffInHours($last['at']) / 24;
                $growth = $days >= 1 ? ($last['disk_used'] - $first['disk_used']) / $days : 0;
                $daysLeft = $growth > 0 ? (int) floor(($disk[1] - $disk[0]) / $growth) : null;
            }
        }

        return [
            'cpu' => (float) $srv['cpu'],
            'load' => $srv['load'] ?? [0, 0, 0],
            'memory' => (int) $srv['memory'],
            'memory_total' => (int) $srv['memory_total'],
            'net_tx' => (float) ($srv['net_tx'] ?? 0),
            'net_rx' => (float) ($srv['net_rx'] ?? 0),
            'disk' => $disk,
            'days_left' => $daysLeft,
            'day' => count($day) > 1 ? [
                'cpu' => array_column($day, 'cpu'),
                'memory' => array_column($day, 'memory'),
                'net' => array_column($day, 'net_tx'),
                'summary' => $daySum,
            ] : null,
        ];
    }

    /**
     * What the server hosts, with one line of context each.
     *
     * @return list<array<string, mixed>>
     */
    private function inventory(): array
    {
        $month = app(SiteStats::class)->thisMonth();
        $monthBytes = array_sum(array_column($month, 'bytes'));
        $apps = LaravelApp::whereNull('preview_of_id')->count();
        $appAttention = $apps ? LaravelApp::attentionCount() : 0;
        $servers = Database::query()->selectRaw('server, count(*) as n')->groupBy('server')->orderByDesc('n')->pluck('n', 'server');
        $serverNames = ['mysql' => 'MySQL', 'mariadb' => 'MariaDB', 'postgresql' => 'PostgreSQL'];
        $quota = app(Quotas::class)->attentionCount();

        return [
            ['icon' => 'globe', 'name' => __('Siteler'), 'count' => Site::count(), 'url' => route('sites.index', $monthBytes ? ['sort' => 'traffic'] : []),
                'note' => $monthBytes ? __('bu ay :bytes trafik', ['bytes' => Bytes::format($monthBytes)]) : __('Bu ay trafik yok'), 'tone' => null],
            ['icon' => 'layers', 'name' => 'Laravel', 'count' => $apps, 'url' => route('laravel.index'),
                'note' => $appAttention ? trans_choice(':count sorunlu uygulama', $appAttention) : ($apps ? __('Sorun yok') : __('Henüz uygulama yok')), 'tone' => $appAttention ? 'warn' : null],
            ['icon' => 'network', 'name' => __('Alan adları'), 'count' => DnsZone::count(), 'url' => route('dns.index'), 'note' => __('DNS bu sunucuda'), 'tone' => null],
            ['icon' => 'mail', 'name' => __('Posta kutuları'), 'count' => Mailbox::count(), 'url' => route('mail.index'),
                'note' => trans_choice(':count posta alan adı', MailDomain::count()), 'tone' => null],
            ['icon' => 'database', 'name' => __('Veritabanları'), 'count' => (int) $servers->sum(), 'url' => route('databases.index'),
                'note' => $servers->isEmpty() ? __('Henüz veritabanı yok') : $servers->map(fn ($n, $s) => ($serverNames[$s] ?? $s).' '.$n)->join(', '), 'tone' => null],
            ['icon' => 'users', 'name' => __('Müşteriler'), 'count' => Customer::count(), 'url' => route('customers.index'),
                'note' => $quota ? trans_choice(':count müşteri sınıra yakın', $quota) : trans_choice(':count paket', Package::count()), 'tone' => $quota ? 'warn' : null],
        ];
    }

    /**
     * The five sites using the most now, worst state first.
     *
     * @return list<array<string, mixed>>
     */
    private function busiestSites(array $snap): array
    {
        $sites = Site::all()->keyBy('domain');
        $limited = ResourceLimit::active()->pluck('site_id')->flip();
        $attackers = array_count_values(array_filter(array_column(LiveStats::openSuspects($snap), 'target')));
        $rows = [];
        foreach ($snap['sites'] ?? [] as $u) {
            $site = $sites[$u['domain']] ?? null;
            if ($site === null) {
                continue;
            }
            [$badges, $rank] = Health::site($u, $attackers[$u['domain']] ?? 0, isset($limited[$site->id]));
            $rows[] = ['site' => $site, 'u' => $u, 'badge' => $badges[0], 'rank' => $rank];
        }
        usort($rows, fn ($a, $b) => [$a['rank'] > 2, -$a['u']['cpu']] <=> [$b['rank'] > 2, -$b['u']['cpu']]);

        return array_slice($rows, 0, 5);
    }

    /**
     * The shared and Serfex services that run now, with their memory.
     *
     * @return array{list: list<array<string, mixed>>, down: list<array<string, mixed>>}
     */
    private function services(array $snap): array
    {
        $list = array_values(array_filter($snap['services'] ?? [], fn ($s) => in_array($s['group'] ?? '', ['shared', 'serfex'], true)));
        usort($list, fn ($a, $b) => [($a['group'] ?? '') === 'serfex', $a['name']] <=> [($b['group'] ?? '') === 'serfex', $b['name']]);

        return ['list' => array_slice($list, 0, 9), 'more' => max(0, count($list) - 9), 'down' => SystemServices::problems()];
    }

    /**
     * Laravel applications by their latest deployment, newest first.
     *
     * @return Collection<int, LaravelApp>
     */
    private function apps()
    {
        return LaravelApp::whereNull('preview_of_id')->with('site', 'latestDeployment')->get()
            ->sortByDesc(fn (LaravelApp $app) => $app->latestDeployment?->created_at?->getTimestamp() ?? 0)
            ->take(4)->values();
    }
}
