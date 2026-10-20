<?php

namespace App\Services\Settings;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Databases\DatabaseServers;
use App\Services\Security\Firewall;
use App\Support\Locales;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Araçlar ve Ayarlar → Genel and Sunucu: the panel's time zone, session
 * length and port, and the server's name and time zone.
 *
 * The time zone is one setting for the server (timedatectl, so cron and
 * logs agree) and the panel (times shown, schedules such as the 03:00
 * backup). Dates stay stored in UTC.
 *
 * A port change never locks anyone out: servik-web listens on the old
 * and the new port until an administrator confirms from the new one. If
 * nobody does within CONFIRM_MINUTES, servik:settings-check goes back to
 * the old port.
 */
class PanelSettings
{
    public const CACHE_KEY = 'servik.panel.settings';

    /** Minutes without activity before a sign-in ends. */
    public const DEFAULT_SESSION_MINUTES = 120;

    /** How long a port change waits to be confirmed from the new port. */
    public const CONFIRM_MINUTES = 10;

    /** Ports the panel may not take: the sites' web ports, Apache behind Nginx, PowerDNS's API. */
    public const RESERVED_PORTS = [80, 443, 7080, 8081];

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Applies the saved time zone and session length to this request or
     * command (AppServiceProvider). Quietly does nothing before the
     * database exists.
     */
    public static function apply(): void
    {
        try {
            $saved = Cache::rememberForever(self::CACHE_KEY, fn () => [
                'timezone' => Setting::get('panel.timezone'),
                'session' => Setting::get('panel.session_minutes'),
                'locale' => Setting::get('panel.locale'),
            ]);
        } catch (Throwable) {
            $saved = [];
        }
        if (! empty($saved['timezone'])) {
            config(['servik.timezone' => $saved['timezone']]);
        }
        if (! empty($saved['session'])) {
            config(['session.lifetime' => (int) $saved['session']]);
        }
        config(['app.schedule_timezone' => config('servik.timezone')]);
        if (Locales::supports($saved['locale'] ?? null)) {
            config(['servik.locale' => $saved['locale']]);
        }
        // Commands, jobs and anything without a person speak the server's
        // language; SetLocale switches requests to the person's.
        Locales::apply(Locales::serverDefault());
    }

    public function timezone(): string
    {
        return (string) (Setting::get('panel.timezone') ?: config('servik.timezone'));
    }

    /**
     * The server's default language: for the sign-in page when the browser
     * asks for none of the panel's, for people who did not choose one,
     * and for commands and webhooks.
     */
    public function locale(): string
    {
        return Locales::supports($saved = Setting::get('panel.locale')) ? $saved : Locales::serverDefault();
    }

    public function setLocale(string $locale): void
    {
        Setting::put('panel.locale', $locale);
        config(['servik.locale' => $locale]);
        $this->forget();
    }

    public function sessionMinutes(): int
    {
        return (int) (Setting::get('panel.session_minutes') ?: self::DEFAULT_SESSION_MINUTES);
    }

    /**
     * Sets the server's time zone, then the panel's.
     *
     * @throws AgentException
     */
    public function setTimezone(string $timezone): void
    {
        $this->agent->call('system.timezone.set', ['timezone' => $timezone], config('servik.agent.job_timeout'));
        Setting::put('panel.timezone', $timezone);
        $this->forget();
    }

    public function setSessionMinutes(int $minutes): void
    {
        Setting::put('panel.session_minutes', $minutes);
        $this->forget();
    }

    /**
     * The time zones the server knows, Europe/Istanbul style.
     *
     * @return list<string>
     */
    public function timezones(): array
    {
        try {
            $zones = (array) $this->agent->call('system.timezones');
        } catch (AgentException) {
            $zones = [];
        }

        return $zones !== [] ? array_values($zones) : ['Europe/Istanbul', 'UTC'];
    }

    /**
     * The server: hostname, operating system, CPUs, memory, time zone,
     * uptime and addresses.
     *
     * @return array<string, mixed>
     *
     * @throws AgentException
     */
    public function server(): array
    {
        return (array) $this->agent->call('system.info');
    }

    /**
     * @throws AgentException
     */
    public function setHostname(string $hostname): void
    {
        $this->agent->call('system.hostname.set', ['hostname' => $hostname], config('servik.agent.job_timeout'));
        Setting::put('server.hostname', $hostname);
    }

    /**
     * The port the panel (and phpMyAdmin, Adminer) answers on.
     */
    public function port(): int
    {
        return app(DatabaseServers::class)->managerPort();
    }

    /**
     * The port change waiting to be confirmed: {from, to, until}.
     *
     * @return array{from: int, to: int, until: string}|null
     */
    public function portChange(): ?array
    {
        return Setting::get('panel.port_change');
    }

    /**
     * Every port servik-web listens on now, the new one first while a
     * change waits.
     *
     * @return list<int>
     */
    public function ports(): array
    {
        $change = $this->portChange();

        return $change ? [(int) $change['to'], (int) $change['from']] : [$this->port()];
    }

    /**
     * Why $port can't be the panel's, or null.
     */
    public function portProblem(int $port): ?string
    {
        return match (true) {
            $port < 1024 || $port > 65535 => 'Port 1024 ile 65535 arasında olmalı.',
            in_array($port, self::RESERVED_PORTS, true) => $port.' sitelerin, Apache\'nin ya da DNS sunucusunun portu; başka bir port seçin.',
            $port === $this->port() => 'Panel zaten bu portta.',
            default => null,
        };
    }

    /**
     * Opens the panel on $port too. The old port keeps working until the
     * change is confirmed from the new one, or reverted.
     *
     * @throws AgentException
     */
    public function changePort(int $port): void
    {
        $from = $this->port();
        $this->agent->call('panel.web.ports', ['ports' => [$port, $from]], config('servik.agent.job_timeout'));
        Setting::put('panel.port_change', ['from' => $from, 'to' => $port, 'until' => now()->addMinutes(self::CONFIRM_MINUTES)->toIso8601String()]);
        $this->reapplyFirewall();
    }

    /**
     * Keeps the new port and closes the old one.
     *
     * @throws AgentException
     */
    public function confirmPort(): void
    {
        $change = $this->portChange();
        if ($change === null) {
            return;
        }
        $this->agent->call('panel.web.ports', ['ports' => [(int) $change['to']]], config('servik.agent.job_timeout'));
        Setting::put('databases.manager_port', (int) $change['to']);
        Setting::put('panel.port_change', null);
        $this->reapplyFirewall();
    }

    /**
     * Goes back to the old port only.
     *
     * @throws AgentException
     */
    public function revertPort(): void
    {
        $change = $this->portChange();
        if ($change === null) {
            return;
        }
        $this->agent->call('panel.web.ports', ['ports' => [(int) $change['from']]], config('servik.agent.job_timeout'));
        Setting::put('panel.port_change', null);
        $this->reapplyFirewall();
    }

    /**
     * The port to send with db.manager.setup: none while a change waits
     * for confirmation, so the agent keeps both ports.
     *
     * @return array{port?: int}
     */
    public function managerSetupParams(): array
    {
        return $this->portChange() === null ? ['port' => $this->port()] : [];
    }

    /**
     * True when a port change waited too long to be confirmed.
     */
    public function portChangeExpired(): bool
    {
        $change = $this->portChange();

        return $change !== null && Carbon::parse($change['until'])->isPast();
    }

    /**
     * Installs the panel's own certificate in place of Let's Encrypt's.
     *
     * @throws AgentException
     */
    public function uploadCertificate(string $certificate, string $key, string $chain): Certificate
    {
        // The agent takes the chain after the certificate, in one PEM.
        $info = $this->agent->call('ssl.panel.install', [
            'certificate' => trim($certificate)."\n".(trim($chain) === '' ? '' : trim($chain)."\n"),
            'private_key' => trim($key)."\n",
        ], config('servik.agent.job_timeout'));
        $panel = Certificate::firstOrNew(['kind' => 'panel'], ['name' => 'panel']);
        $panel->fill([
            'source' => Certificate::CUSTOM,
            'challenge' => null,
            'auto_renew' => false,
            'status' => CertificateStatus::Active,
        ])->save();
        $panel->recordInstalled($info);

        return $panel;
    }

    private function reapplyFirewall(): void
    {
        try {
            app(Firewall::class)->reapply();
        } catch (AgentException $e) {
            report($e);
        }
    }

    private function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
        self::apply();
    }
}
