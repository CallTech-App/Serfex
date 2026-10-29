<?php

namespace App\Services\Components;

use App\Jobs\RunComponentOperation;
use App\Models\ComponentOperation;
use App\Models\PanelUpdate;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Ubuntu's own packages (Yazılım Yönetimi → Ubuntu güncellemeleri): which
 * have updates, upgrading all of them or a chosen few, Ubuntu's automatic
 * security updates and the restart a new kernel asks for. The agent runs
 * apt; an upgrade is a ComponentOperation of the "system" component, so
 * it shares the one-at-a-time rule and the progress window with the
 * other software.
 *
 * Only full administrators change anything here.
 */
class SystemPackages
{
    /** The ComponentOperation component and action of an upgrade. */
    public const COMPONENT = 'system';

    public const ACTION = 'packages';

    /** Where the dashboard's summary is kept between agent calls. */
    public const SUMMARY_KEY = 'serfex.packages.summary';

    /** @var array<string, mixed>|null packages.list, once per request */
    private ?array $list = null;

    public function __construct(
        private readonly AgentClient $agent,
        private readonly Components $components,
    ) {}

    /**
     * packages.list: the packages with updates, the reboot notice and the
     * automatic updates. Null when the agent cannot be reached; with
     * $error the reason is set.
     *
     * @return array<string, mixed>|null
     */
    public function list(?string &$error = null, float $timeout = 120): ?array
    {
        if ($this->list !== null) {
            return $this->list;
        }
        try {
            $this->list = (array) $this->agent->call('packages.list', [], $timeout);
        } catch (AgentException $e) {
            $error = $e instanceof AgentUnavailableException ? null : $e->reason;

            return null;
        }
        Cache::put(self::SUMMARY_KEY, $this->summarize($this->list), now()->addMinutes(10));

        return $this->list;
    }

    /**
     * Counts for Genel Bakış, from the agent at most every ten minutes:
     * updates, security updates and the reboot notice. Null when the agent
     * cannot tell.
     *
     * @return array{updates: int, security: int, reboot: bool, reboot_packages: list<string>, since: ?string, auto: bool}|null
     */
    public function summary(): ?array
    {
        $cached = Cache::get(self::SUMMARY_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        // The dashboard does not wait long for apt.
        $list = $this->list(timeout: 20);

        return $list === null ? null : $this->summarize($list);
    }

    public function forget(): void
    {
        $this->list = null;
        Cache::forget(self::SUMMARY_KEY);
    }

    /**
     * The table's rows: security updates first, then by name.
     *
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        $rows = [];
        foreach ($this->list()['packages'] ?? [] as $p) {
            $component = $p['component'] ?? null;
            $rows[] = [
                'name' => (string) $p['name'],
                'installed' => $p['installed'] ?? null,
                'version' => (string) ($p['version'] ?? ''),
                'security' => (bool) ($p['security'] ?? false),
                'source' => self::origin($p['origin'] ?? null),
                'new' => (bool) ($p['new'] ?? false),
                'held' => (bool) ($p['held'] ?? false),
                'kept_back' => (bool) ($p['kept_back'] ?? false),
                'phased' => (bool) ($p['phased'] ?? false),
                'component' => $component,
                'component_name' => $component ? $this->components->name($component) : null,
                'selectable' => ! ($p['new'] ?? false) && ! ($p['held'] ?? false) && ! ($p['phased'] ?? false),
            ];
        }
        usort($rows, fn ($a, $b) => [! $a['security'], $a['name']] <=> [! $b['security'], $b['name']]);

        return $rows;
    }

    /**
     * Starts upgrading $packages (null: everything "update all" brings)
     * in the background. Returns the operation, or why it cannot start.
     *
     * @param  list<string>|null  $packages
     */
    public function start(?array $packages, ?User $user): ComponentOperation|string
    {
        if (! $user?->isFullAdmin()) {
            return __('Ubuntu paketlerini yalnızca tam yetkili yöneticiler güncelleyebilir.');
        }
        // Read first: it also closes operations whose job is gone.
        $statuses = $this->components->statuses($error);
        if (ComponentOperation::running()->exists() || $this->components->databaseSetupRunning()) {
            return __('Başka bir kurulum sürüyor. Bitince yeniden deneyin.');
        }
        if (PanelUpdate::running()->exists()) {
            return __('Serfex güncelleniyor. Bitince yeniden deneyin.');
        }
        if ($statuses === null) {
            return $error ? __('Sunucu ajanı hata verdi: :error', ['error' => $error]) : __('Sunucu ajanına ulaşılamıyor.');
        }
        $rows = collect($this->rows())->keyBy('name');
        if ($packages !== null) {
            $packages = array_values(array_unique($packages));
            if ($packages === []) {
                return __('Güncellenecek paketi seçin.');
            }
            foreach ($packages as $name) {
                $row = $rows->get($name);
                if ($row === null) {
                    return __(':name için güncelleme yok.', ['name' => $name]);
                }
                if (! $row['selectable']) {
                    return __(':name tek başına güncellenemez.', ['name' => $name]);
                }
            }
        } elseif ($rows->doesntContain(fn ($r) => $r['selectable'] && ! $r['kept_back'])) {
            return __('Güncellenecek paket yok.');
        }

        $operation = ComponentOperation::create([
            'component' => self::COMPONENT,
            'action' => self::ACTION,
            'packages' => $packages,
            'run' => 'p'.Str::lower(Str::random(23)),
            'user_id' => $user->id,
        ]);
        RunComponentOperation::dispatch($operation);

        return $operation;
    }

    /**
     * Where a package comes from, for people: the agent gives the archive's
     * label ("Ubuntu", "LP-PPA-ondrej-php") or its host and path.
     */
    public static function origin(?string $origin): ?string
    {
        if ($origin === null || $origin === '') {
            return null;
        }

        return match (true) {
            $origin === 'Ubuntu' || str_ends_with(Str::before($origin, '/'), 'ubuntu.com') => 'Ubuntu',
            str_contains($origin, 'ondrej-php') || str_contains($origin, '/ondrej/php') => 'ppa:ondrej/php',
            str_contains($origin, 'postgresql.org') => 'apt.postgresql.org',
            str_contains($origin, 'mysql') => 'repo.mysql.com',
            str_contains($origin, 'mariadb') => 'dlm.mariadb.com',
            default => Str::before($origin, '/'),
        };
    }

    /**
     * @param  array<string, mixed>  $list
     * @return array{updates: int, security: int, reboot: bool, reboot_packages: list<string>, since: ?string, auto: bool}
     */
    private function summarize(array $list): array
    {
        $waiting = collect($list['packages'] ?? [])->reject(fn ($p) => ($p['new'] ?? false) || ($p['held'] ?? false));

        return [
            'updates' => $waiting->count(),
            'security' => $waiting->where('security', true)->count(),
            'reboot' => (bool) ($list['reboot']['required'] ?? false),
            'reboot_packages' => array_values($list['reboot']['packages'] ?? []),
            'since' => $list['reboot']['since'] ?? null,
            'auto' => (bool) ($list['auto']['enabled'] ?? false),
        ];
    }
}
