<?php

namespace App\Services\Setup;

use App\Models\BackupTarget;
use App\Models\Certificate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsSettings;
use App\Services\Security\Firewall;
use App\Services\Settings\Notifications;
use App\Services\Settings\PanelSettings;
use App\Services\Updates\Updates;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The first run of a new server: the one-time setup link that opens the
 * panel before anybody has an account, and the setup wizard's progress.
 *
 * install.sh prints the link (serfex:setup-url); whoever opens it creates
 * the first administrator. The link is the only way in before that, works
 * once and for SETUP_HOURS, and only root on the server can make a new
 * one (sudo serfex setup-url). Root's password never goes through the
 * browser.
 *
 * The wizard walks through what a fresh hosting server needs. Each step
 * is saved or skipped; Genel Bakış lists what is still open until the
 * administrator finishes the wizard, which can be run again from Araçlar
 * ve Ayarlar.
 */
class FirstRun
{
    /** How long a setup link works. */
    public const SETUP_HOURS = 24;

    /** The wizard's steps after the administrator account: slug => name. */
    public const STEPS = [
        'sunucu' => 'Sunucu',
        'ad-sunuculari' => 'Ad sunucuları',
        'panel' => 'Panel adresi',
        'bildirimler' => 'Bildirimler',
        'guvenlik' => 'Güvenlik duvarı',
        'varsayilanlar' => 'Varsayılanlar',
        'yedekler' => 'Yedekler',
    ];

    private const TOKEN = 'setup.token';

    private const PROGRESS = 'setup.wizard';

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * True while the server has no administrator who can change the panel:
     * the state a setup link is for.
     */
    public function needsAdministrator(): bool
    {
        return ! User::where('is_admin', true)->whereNull('customer_id')->whereNull('removed_at')->whereNull('invite_token')
            ->where(fn ($q) => $q->where('role', 'full')->orWhereNull('role'))->exists();
    }

    /**
     * A new setup link's token; the previous link stops working.
     *
     * @return array{token: string, expires_at: Carbon}
     */
    public function newToken(): array
    {
        $token = Str::random(48);
        $expires = now()->addHours(self::SETUP_HOURS);
        Setting::put(self::TOKEN, ['hash' => hash('sha256', $token), 'expires_at' => $expires->toIso8601String()]);

        return ['token' => $token, 'expires_at' => $expires];
    }

    /**
     * True when $token is the current setup link's and still works.
     */
    public function validToken(string $token): bool
    {
        $saved = Setting::get(self::TOKEN);
        if (! is_array($saved) || ! isset($saved['hash'], $saved['expires_at']) || ! $this->needsAdministrator()) {
            return false;
        }

        return hash_equals($saved['hash'], hash('sha256', $token)) && Carbon::parse($saved['expires_at'])->isFuture();
    }

    /**
     * Creates the first administrator from the setup link and closes the
     * link.
     */
    public function createAdministrator(string $name, string $email, string $password, ?string $locale): User
    {
        $user = User::create([
            'name' => $name,
            'email' => mb_strtolower($email),
            'password' => $password,
        ]);
        $user->forceFill(['role' => 'full', 'locale' => $locale, 'email_verified_at' => now()])->save();
        Setting::put(self::TOKEN, null);

        return $user;
    }

    /**
     * The addresses the panel answers on, for the setup link: the server's
     * first IPv4 address (the panel's own name rarely points here yet on a
     * new server), then its name.
     *
     * @return list<string> https://host:port
     */
    public function panelUrls(): array
    {
        $port = app(PanelSettings::class)->port();
        $hosts = [];
        try {
            $found = (array) $this->agent->call('system.addresses');
            if (! empty($found['ipv4'][0])) {
                $hosts[] = $found['ipv4'][0];
            }
        } catch (AgentException) {
            // The name below still works where it resolves.
        }
        foreach ([Setting::get('panel.hostname'), Setting::get('server.hostname'), gethostname() ?: null] as $name) {
            if (is_string($name) && str_contains($name, '.') && ! in_array($name, $hosts, true)) {
                $hosts[] = $name;
                break;
            }
        }
        if ($hosts === []) {
            $hosts[] = (string) (gethostname() ?: 'localhost');
        }

        return array_map(fn ($h) => 'https://'.$h.':'.$port, $hosts);
    }

    /**
     * @return array{steps: array<string, string>, started_at: ?string, completed_at: ?string}
     */
    public function progress(): array
    {
        $saved = (array) Setting::get(self::PROGRESS, []);

        return [
            'steps' => array_intersect_key((array) ($saved['steps'] ?? []), self::STEPS),
            'started_at' => $saved['started_at'] ?? null,
            'completed_at' => $saved['completed_at'] ?? null,
        ];
    }

    /**
     * Records a step as saved (done) or passed over (skipped).
     */
    public function mark(string $step, string $state): void
    {
        if (! isset(self::STEPS[$step]) || ! in_array($state, ['done', 'skipped'], true)) {
            return;
        }
        $progress = $this->progress();
        // A saved step stays saved when it is later passed over.
        if ($state === 'done' || ($progress['steps'][$step] ?? null) !== 'done') {
            $progress['steps'][$step] = $state;
        }
        $this->save($progress);
    }

    public function start(): void
    {
        $progress = $this->progress();
        if ($progress['started_at'] === null) {
            $progress['started_at'] = now()->toIso8601String();
            $this->save($progress);
        }
    }

    public function complete(): void
    {
        $progress = $this->progress();
        $progress['started_at'] ??= now()->toIso8601String();
        $progress['completed_at'] = now()->toIso8601String();
        $this->save($progress);
    }

    public function started(): bool
    {
        return $this->progress()['started_at'] !== null;
    }

    public function completed(): bool
    {
        return $this->progress()['completed_at'] !== null;
    }

    /**
     * Genel Bakış's "İlk kurulum" list: each step, whether it is done (the
     * wizard saved it, or the setting is already in place), skipped or
     * open, and the step's address in the wizard.
     *
     * @return list<array{step: string, name: string, state: string, detail: ?string}>
     */
    public function checklist(): array
    {
        $saved = $this->progress()['steps'];
        $out = [];
        foreach (self::STEPS as $step => $name) {
            [$configured, $detail] = $this->configured($step);
            $state = ($saved[$step] ?? null) === 'done' || $configured ? 'done' : ($saved[$step] ?? 'open');
            $out[] = ['step' => $step, 'name' => __($name), 'state' => $state, 'detail' => $detail];
        }

        return $out;
    }

    /**
     * Steps neither done nor skipped.
     */
    public function openCount(): int
    {
        return count(array_filter($this->checklist(), fn ($i) => $i['state'] === 'open'));
    }

    /**
     * Whether a step's setting is already in place without the wizard (on
     * a server set up before it existed), and a short note on what is set
     * (shown once the step is done).
     *
     * @return array{0: bool, 1: ?string}
     */
    private function configured(string $step): array
    {
        return match ($step) {
            'ad-sunuculari' => ($ns = app(DnsSettings::class)->nameservers()) !== [] ? [true, implode(', ', $ns)] : [false, null],
            'panel' => ($host = Setting::get('panel.hostname')) && Certificate::where('kind', 'panel')->whereNotNull('not_after')->exists() ? [true, $host] : [false, $host ?: __('IP adresi ve port')],
            'bildirimler' => ($emails = app(Notifications::class)->settings()['emails']) !== [] ? [true, implode(', ', $emails)] : [false, null],
            'guvenlik' => app(Firewall::class)->enabled() ? [true, __('Açık')] : [false, null],
            'yedekler' => BackupTarget::exists() ? [true, BackupTarget::orderBy('id')->value('name')] : [false, null],
            'sunucu' => [false, Setting::get('server.hostname')],
            'varsayilanlar' => [false, implode(' · ', array_filter([
                Setting::get('web.default_stack') === 'apache' ? 'Apache' : 'Nginx',
                ($php = Setting::get('php.default_version')) ? 'PHP '.$php : null,
                __(Updates::CHANNELS[app(Updates::class)->channel()]),
            ]))],
            default => [false, null],
        };
    }

    /**
     * @param  array{steps: array<string, string>, started_at: ?string, completed_at: ?string}  $progress
     */
    private function save(array $progress): void
    {
        Setting::put(self::PROGRESS, $progress);
    }
}
