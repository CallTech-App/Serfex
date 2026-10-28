<?php

namespace App\Livewire\Monitor;

use App\Models\IpBlock;
use App\Models\MonitorSample;
use App\Models\ResourceLimit;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Monitor\Health;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\LiveStats;
use App\Services\Monitor\MailQueue;
use App\Services\Monitor\MonitorException;
use App\Services\Monitor\ResourceLimits;
use App\Services\Stats\ServerHistory;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Canlı İzleme: which site uses the CPU, memory, PHP workers, database,
 * disk and mail, which one is under attack, what waits in the mail queue
 * and what the system services use; with one-click interventions.
 */
#[Layout('components.layouts.app')]
#[Title('Canlı İzleme · Serfex')]
class LiveMonitor extends Component
{
    public const TABS = ['sites' => 'Siteler', 'attacks' => 'Saldırılar', 'mail' => 'Posta kuyruğu', 'system' => 'Sistem'];

    /** Range => [label, seconds the agent averages over]. */
    public const RANGES = ['now' => ['Şu an', 10], '5m' => ['Son 5 dk', 300], '1h' => ['Son 1 saat', 3600], '24h' => ['Son 24 saat', 86400]];

    /** Choices of the limit form; 0 leaves a resource unlimited. */
    public const CPU_CHOICES = [0 => 'Sınırsız', 25 => '%25', 50 => '%50', 100 => '%100', 200 => '%200'];

    public const MEMORY_CHOICES = [0 => 'Sınırsız', 256 => '256 MB', 512 => '512 MB', 1024 => '1 GB', 2048 => '2 GB'];

    public const PHP_CHOICES = [0 => 'Sınırsız', 2 => '2 işçi', 4 => '4 işçi', 6 => '6 işçi'];

    #[Url(except: 'sites')]
    public string $tab = 'sites';

    #[Url(as: 'range', except: 'now')]
    public string $range = 'now';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** The Sistem tab's history range (ServerHistory::RANGES). */
    #[Url(as: 'history', except: '24h')]
    public string $span = '24h';

    /** The site whose intervention dialog is open. */
    public ?int $siteId = null;

    public bool $acting = false;

    /** @var array{cpu: int, memory: int, php: int, minutes: int} */
    public array $limit = ['cpu' => 100, 'memory' => 0, 'php' => 0, 'minutes' => 30];

    public int $attackMinutes = 60;

    public bool $blocking = false;

    /** @var array{address: string, minutes: int, reason: string, site: int|null, kind: string|null} */
    public array $block = ['address' => '', 'minutes' => 60, 'reason' => '', 'site' => null, 'kind' => null];

    public ?string $actionError = null;

    public function mount(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'sites';
        }
        if (! isset(self::RANGES[$this->range])) {
            $this->range = 'now';
        }
    }

    public function updatedRange(): void
    {
        if (! isset(self::RANGES[$this->range])) {
            $this->range = 'now';
        }
    }

    public function setTab(string $tab): void
    {
        if (isset(self::TABS[$tab])) {
            $this->tab = $tab;
        }
    }

    // --- Site dialog -------------------------------------------------

    public function open(int $siteId): void
    {
        $site = Site::find($siteId);
        if ($site === null) {
            return;
        }
        $this->siteId = $site->id;
        $current = $site->activeLimit();
        $this->limit = [
            'cpu' => $current->cpu ?? 100,
            'memory' => $current->memory_mb ?? 0,
            'php' => $current->php_workers ?? 0,
            'minutes' => 30,
        ];
        $this->actionError = null;
        $this->acting = true;
    }

    public function close(): void
    {
        $this->acting = false;
        $this->siteId = null;
    }

    public function applyLimit(ResourceLimits $limits): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }
        $cpu = (int) $this->limit['cpu'];
        $memory = (int) $this->limit['memory'];
        $php = (int) $this->limit['php'];
        $minutes = (int) $this->limit['minutes'];
        if (! isset(self::CPU_CHOICES[$cpu], self::MEMORY_CHOICES[$memory], self::PHP_CHOICES[$php], ResourceLimits::DURATIONS[$minutes])) {
            return;
        }
        $previous = $site->activeLimit();
        try {
            $limit = $limits->apply($site, $cpu, $memory, $php, $minutes, by: auth()->user());
        } catch (MonitorException $e) {
            $this->actionError = $e->getMessage();

            return;
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        $this->actionError = null;
        $this->dispatch('toast',
            message: __(':domain :duration sınırlandı: :summary.', ['domain' => $site->domain, 'duration' => __(ResourceLimits::DURATIONS[$minutes]), 'summary' => $limit->summary()]),
            source: $this->getId(),
            undo: $previous === null ? ['method' => 'liftLimit', 'args' => [$limit->id]] : null,
        );
    }

    public function liftLimit(int $id, ResourceLimits $limits): void
    {
        $limit = ResourceLimit::with('site')->find($id);
        if ($limit === null || ! $limit->isActive()) {
            return;
        }
        try {
            $limits->lift($limit);
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        $this->dispatch('toast', message: __(':domain için sınır kaldırıldı.', ['domain' => $limit->site->domain]));
    }

    public function restartPhp(ResourceLimits $limits): void
    {
        $site = Site::find($this->siteId);
        if ($site === null) {
            return;
        }
        try {
            $n = $limits->restartPhp($site);
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        $this->actionError = null;
        $this->dispatch('toast', message: $n > 0
            ? __(':domain için :n PHP işçisi durduruldu; yeni istekler yeni işçilerle karşılanıyor.', ['domain' => $site->domain, 'n' => $n])
            : __(':domain şu an PHP işçisi çalıştırmıyordu; yeniden başlatılacak bir şey yok.', ['domain' => $site->domain]));
    }

    /**
     * Blocks every address attacking the open site.
     */
    public function blockAttackers(IpBlocks $blocks, LiveStats $stats): void
    {
        $site = Site::find($this->siteId);
        if ($site === null || ! isset(IpBlocks::DURATIONS[$this->attackMinutes])) {
            return;
        }
        try {
            $ids = [];
            foreach (self::attackers(LiveStats::openSuspects($stats->snapshot(10)), $site->domain) as $a) {
                $ids[] = $blocks->block($a['ip'], $this->attackMinutes, implode(', ', $a['rules']), $site, $a['kind'], auth()->user(), protect: [(string) request()->ip()])->id;
            }
        } catch (MonitorException $e) {
            $this->actionError = $e->getMessage();

            return;
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        if ($ids === []) {
            $this->dispatch('toast', message: __('Engellenecek saldırgan adres kalmadı.'));

            return;
        }
        $this->actionError = null;
        $this->dispatch('toast',
            message: $this->attackMinutes === 0
                ? __(':ids adres kalıcı olarak engellendi.', ['ids' => count($ids)])
                : __(':ids adres :durations engellendi.', ['ids' => count($ids), 'durations' => IpBlocks::DURATIONS[$this->attackMinutes]]),
            source: $this->getId(),
            undo: ['method' => 'unblockMany', 'args' => [$ids]],
        );
    }

    // --- Blocking ----------------------------------------------------

    public function openBlock(string $address = '', ?int $site = null, ?string $kind = null, string $reason = ''): void
    {
        $this->block = ['address' => $address, 'minutes' => 60, 'reason' => $reason, 'site' => $site, 'kind' => $kind];
        $this->resetValidation();
        // One dialog at a time: the block dialog replaces the site's.
        $this->acting = false;
        $this->blocking = true;
    }

    public function saveBlock(IpBlocks $blocks): void
    {
        $this->validate([
            'block.address' => ['required', 'string', 'max:64'],
            'block.minutes' => ['required', 'integer', 'in:'.implode(',', array_keys(IpBlocks::DURATIONS))],
            'block.reason' => ['nullable', 'string', 'max:200'],
        ], [], ['block.address' => 'adres', 'block.reason' => 'neden']);
        $site = $this->block['site'] ? Site::find($this->block['site']) : null;
        try {
            $b = $blocks->block((string) $this->block['address'], (int) $this->block['minutes'], trim((string) $this->block['reason']), $site,
                $this->block['kind'], auth()->user(), protect: [(string) request()->ip()]);
        } catch (MonitorException $e) {
            $this->addError('block.address', $e->getMessage());

            return;
        } catch (AgentException $e) {
            $this->addError('block.address', self::agentMessage($e));

            return;
        }
        $this->blocking = false;
        $this->dispatch('toast',
            message: $b->expires_at
                ? __(':address :duration engellendi.', ['address' => $b->address, 'duration' => IpBlocks::DURATIONS[(int) $this->block['minutes']]])
                : __(':address kalıcı olarak engellendi.', ['address' => $b->address]),
            source: $this->getId(),
            undo: ['method' => 'unblock', 'args' => [$b->id]],
        );
    }

    public function unblock(int $id, IpBlocks $blocks): void
    {
        $this->unblockMany([$id], $blocks);
    }

    /**
     * @param  list<int>  $ids
     */
    public function unblockMany(array $ids, IpBlocks $blocks): void
    {
        $done = [];
        foreach (IpBlock::whereKey(array_map('intval', $ids))->get() as $b) {
            try {
                $blocks->unblock($b);
                $done[$b->id] = $b->address;
            } catch (AgentException $e) {
                $this->actionError = self::agentMessage($e);
            }
        }
        if ($done !== []) {
            $this->dispatch('toast',
                message: count($done) === 1 ? __(':done engeli kaldırıldı.', ['done' => reset($done)]) : __(':done adresin engeli kaldırıldı.', ['done' => count($done)]),
                source: $this->getId(),
                undo: ['method' => 'reblockMany', 'args' => [array_keys($done)]],
            );
        }
    }

    /**
     * Undoes unblockMany(): each block comes back for the time it had left.
     *
     * @param  list<int>  $ids
     */
    public function reblockMany(array $ids, IpBlocks $blocks): void
    {
        foreach (IpBlock::whereKey(array_map('intval', $ids))->get() as $b) {
            try {
                $blocks->reblock($b);
            } catch (MonitorException $e) {
                $this->actionError = $e->getMessage();
            } catch (AgentException $e) {
                $this->actionError = self::agentMessage($e);
            }
        }
    }

    // --- Mail queue --------------------------------------------------

    public function flushQueue(MailQueue $queue): void
    {
        try {
            $queue->flush();
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        $this->dispatch('toast', message: __('Bekleyen iletiler yeniden deneniyor.'));
    }

    public function hold(string $domain, MailQueue $queue): void
    {
        try {
            $n = $queue->hold($domain);
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        $name = $domain === '' ? __('Geri dönen iletiler') : $domain;
        $this->dispatch('toast', message: __(':name: :n ileti bekletmeye alındı.', ['name' => $name, 'n' => $n]), source: $this->getId(),
            undo: $n > 0 ? ['method' => 'release', 'args' => [$domain]] : null);
    }

    public function release(string $domain, MailQueue $queue): void
    {
        try {
            $n = $queue->release($domain);
        } catch (AgentException $e) {
            $this->actionError = self::agentMessage($e);

            return;
        }
        $name = $domain === '' ? __('Geri dönen iletiler') : $domain;
        $this->dispatch('toast', message: __(':name: :n ileti serbest bırakıldı.', ['name' => $name, 'n' => $n]));
    }

    // --- Rendering ---------------------------------------------------

    public static function agentMessage(AgentException $e): string
    {
        return $e instanceof AgentUnavailableException
            ? __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.')
            : $e->reason;
    }

    /**
     * @param  array<string, mixed>  $s  a suspect from monitor.live
     */
    public static function ruleLabel(array $s): string
    {
        return match ($s['kind']) {
            'flood' => __('İstek seli'),
            'login' => __('Giriş denemesi').(($s['detail'] ?? '') !== '' ? ' · '.$s['detail'] : ''),
            'scan' => __('Tarama (404)'),
            'mail' => __('Posta girişi denemesi').(($s['detail'] ?? '') !== '' ? ' · '.strtoupper($s['detail']) : ''),
            'ssh' => __('SSH girişi denemesi'),
            default => $s['kind'],
        };
    }

    /**
     * The open suspects attacking a site, one entry per address.
     *
     * @param  list<array<string, mixed>>  $open
     * @return list<array{ip: string, kind: string, rules: list<string>, count: int}>
     */
    public static function attackers(array $open, string $domain): array
    {
        $by = [];
        foreach ($open as $s) {
            if ($s['target'] !== $domain) {
                continue;
            }
            $a = $by[$s['ip']] ??= ['ip' => $s['ip'], 'kind' => $s['kind'], 'rules' => [], 'count' => 0];
            $a['rules'][] = self::ruleLabel($s);
            $a['count'] = max($a['count'], (int) $s['count']);
            $by[$s['ip']] = $a;
        }
        usort($by, fn ($a, $b) => $b['count'] <=> $a['count']);

        return $by;
    }

    /**
     * Suspects merged per address and target, so an address that broke
     * two rules on one site is one row; busiest first.
     *
     * @param  list<array<string, mixed>>  $suspects
     * @return list<array<string, mixed>>
     */
    public static function suspectRows(array $suspects): array
    {
        $rows = [];
        foreach ($suspects as $s) {
            $key = $s['ip'].'|'.$s['target'];
            $r = $rows[$key] ?? ['ip' => $s['ip'], 'target' => $s['target'], 'kind' => $s['kind'], 'rules' => [], 'count' => 0, 'peak' => 0, 'since' => $s['since']];
            $r['rules'][] = self::ruleLabel($s);
            $r['count'] = max($r['count'], (int) $s['count']);
            $r['peak'] = max($r['peak'], (int) $s['peak']);
            $r['since'] = min($r['since'], $s['since']);
            $rows[$key] = $r;
        }
        usort($rows, fn ($a, $b) => [$b['count'] > 0, $b['peak']] <=> [$a['count'] > 0, $a['peak']]);

        return $rows;
    }

    public function render(LiveStats $stats, MailQueue $queue): View
    {
        [, $window] = self::RANGES[$this->range];
        $long = $window > 600;
        $siteModel = $this->acting ? Site::find($this->siteId) : null;

        $snap = null;
        $error = null;
        try {
            $snap = $stats->snapshot($long ? 10 : $window, $siteModel && ! $long ? $siteModel->domain : null);
            LiveStats::rememberAttention($snap);
        } catch (AgentException $e) {
            $error = self::agentMessage($e);
        }

        $sites = Site::with('systemUser')->get()->keyBy('domain');
        $limits = ResourceLimit::active()->get()->keyBy('site_id');
        $blocks = IpBlock::active()->with('site', 'user')->latest('id')->get();
        $open = $snap ? LiveStats::openSuspects($snap) : [];
        $attackers = array_count_values(array_filter(array_column($open, 'target')));

        $history = $long && $siteModel ? $this->historyFromSamples($siteModel, $window) : ($snap['history'] ?? []);
        $averages = $long ? $this->averages($window) : [];

        $rows = [];
        foreach ($snap['sites'] ?? [] as $u) {
            $site = $sites[$u['domain']] ?? null;
            if ($site === null) {
                continue;
            }
            if ($this->search !== '' && ! str_contains($u['domain'], mb_strtolower(trim($this->search)))) {
                continue;
            }
            [$badges, $rank] = Health::site($u, $attackers[$u['domain']] ?? 0, isset($limits[$site->id]));
            if ($long) {
                $a = $averages[$site->id] ?? null;
                $u = array_replace($u, [
                    'cpu' => (float) ($a->cpu ?? 0), 'memory' => (int) ($a->memory ?? 0), 'io' => (float) ($a->io ?? 0),
                    'requests' => (float) ($a->requests ?? 0), 'mail_per_hour' => (float) ($a->mail_per_hour ?? 0),
                ]);
                $u['php']['busy'] = (float) ($a->php_busy ?? 0);
                $u['db']['active'] = (float) ($a->db_active ?? 0);
            }
            $rows[] = ['site' => $site, 'u' => $u, 'badges' => $badges, 'rank' => $rank, 'limit' => $limits[$site->id] ?? null];
        }
        usort($rows, fn ($a, $b) => [$a['rank'], -$a['u']['cpu']] <=> [$b['rank'], -$b['u']['cpu']]);

        $current = null;
        if ($siteModel !== null) {
            foreach ($snap['sites'] ?? [] as $u) {
                if ($u['domain'] === $siteModel->domain) {
                    $current = $u;
                }
            }
        }

        $server = null;
        if ($this->tab === 'system') {
            if (! isset(ServerHistory::RANGES[$this->span])) {
                $this->span = '24h';
            }
            // Cached as plain values: the cache does not unserialize objects.
            $cached = Cache::remember('serfex.server-history.'.$this->span, 60, fn () => array_map(
                fn (array $p) => ['at' => $p['at']->getTimestamp()] + $p,
                app(ServerHistory::class)->series($this->span),
            ));
            $points = array_map(fn (array $p) => ['at' => CarbonImmutable::createFromTimestamp($p['at'], config('serfex.timezone'))] + $p, $cached);
            $server = ['points' => $points, 'summary' => ServerHistory::summary($points)];
        }

        $mail = null;
        $mailError = null;
        if ($this->tab === 'mail') {
            try {
                $mail = $queue->get();
            } catch (AgentException $e) {
                $mailError = self::agentMessage($e);
            }
        }

        return view('livewire.monitor.index', [
            'snap' => $snap,
            'error' => $error,
            'rows' => $rows,
            'accounts' => $snap['accounts'] ?? [],
            'suspects' => self::suspectRows($snap['suspects'] ?? []),
            'openCount' => count(self::suspectRows($open)),
            'blocks' => $blocks,
            'sitesByDomain' => $sites,
            'site' => $siteModel,
            'current' => $current,
            'currentLimit' => $siteModel ? ($limits[$siteModel->id] ?? null) : null,
            'siteAttackers' => $siteModel ? self::attackers($open, $siteModel->domain) : [],
            'history' => $history,
            'long' => $long,
            'mail' => $mail,
            'mailError' => $mailError,
            'server' => $server,
            'poll' => match (true) {
                $this->blocking => null,
                $this->acting, $this->tab === 'mail' => '5s',
                $long => '60s',
                default => '2s',
            },
        ]);
    }

    /**
     * Each site's averages over the window, from the per-minute history.
     *
     * @return array<int, object>
     */
    private function averages(int $window): array
    {
        return MonitorSample::query()
            ->whereNotNull('site_id')
            ->where('recorded_at', '>=', now()->subSeconds($window))
            ->groupBy('site_id')
            ->select('site_id', DB::raw('avg(cpu) as cpu'), DB::raw('avg(memory) as memory'), DB::raw('avg(io) as io'),
                DB::raw('avg(requests) as requests'), DB::raw('avg(php_busy) as php_busy'), DB::raw('avg(db_active) as db_active'),
                DB::raw('avg(mail_per_hour) as mail_per_hour'))
            ->get()->keyBy('site_id')->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function historyFromSamples(Site $site, int $window): array
    {
        $rows = MonitorSample::where('site_id', $site->id)->where('recorded_at', '>=', now()->subSeconds($window))->orderBy('recorded_at')->get();
        $step = max(1, (int) ceil($rows->count() / 150));

        return $rows->chunk($step)->map(fn ($c) => [
            'at' => $c->last()->recorded_at->toIso8601String(),
            'cpu' => round((float) $c->avg('cpu'), 1),
            'requests' => round((float) $c->avg('requests'), 2),
        ])->values()->all();
    }
}
