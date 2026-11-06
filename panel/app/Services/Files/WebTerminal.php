<?php

namespace App\Services\Files;

use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Audit\AuditLog;
use Illuminate\Support\Facades\Cache;

/**
 * SSH terminali: the site user's shell in the browser.
 *
 * The agent starts the shell on a pseudo-terminal as the site's own Linux
 * user (never root, never through sshd) and bridges it to a WebSocket on
 * serfex-web's /terminal/socket. The panel only hands out tickets: one
 * random token, good once, for a minute, from the address that asked.
 *
 * Only full administrators open it, only for an account with a shell
 * (SSH erişimi "SFTP ve kabuk") and never while the customer is
 * suspended. Commands cannot be undone, so the page says so and opening
 * is a deliberate click; the opening and the end go into the audit log,
 * what is typed does not.
 */
final class WebTerminal
{
    /** Without input for this long the session ends. */
    public const IDLE_MINUTES = 15;

    /** A session never lasts longer. */
    public const MAX_MINUTES = 120;

    /** Sessions open at once on the server (the agent's limit). */
    public const MAX_SESSIONS = 5;

    /** The agent's error code when MAX_SESSIONS are open. */
    public const LIMIT_CODE = -32013;

    public function __construct(private readonly AgentClient $agent) {}

    /**
     * Why $user may not open a terminal for $site, or null when they may.
     */
    public function refusal(User $user, Site $site): ?string
    {
        $account = $site->systemUser;

        return match (true) {
            ! $user->isFullAdmin() => __('SSH terminalini yalnızca tam yetkili yöneticiler açar.'),
            $account === null => __('Sitenin sistem kullanıcısı yok.'),
            (bool) $account->customer?->isSuspended() => __('Müşteri askıda: askı kalkana kadar terminal açılmaz.'),
            $account->ssh_access !== SshAccess::SHELL => __('Bu sitenin kullanıcısında kabuk erişimi kapalı. SSH erişimi sayfasında "SFTP ve kabuk" seçilince terminal açılır.'),
            $site->status->isBusy() => __('Site üzerinde bir işlem sürüyor; bitince yeniden deneyin.'),
            default => null,
        };
    }

    /**
     * Asks the agent for a session and logs who opened it. Returns the
     * ticket, or why there is none.
     *
     * @return array{id: string, token: string, path: string, expires: string, idle_seconds: int, max_seconds: int}|string
     */
    public function open(User $user, Site $site, ?string $ip): array|string
    {
        if (($refusal = $this->refusal($user, $site)) !== null) {
            return $refusal;
        }
        /** @var SystemUser $account */
        $account = $site->systemUser;
        try {
            $ticket = $this->agent->call('terminal.open', [
                'user' => $account->name,
                'dir' => $site->document_root ?: $account->home,
                'site' => $site->domain,
                'actor' => $user->name,
                'ip' => $ip ?? '',
                'idle_minutes' => self::IDLE_MINUTES,
                'max_minutes' => self::MAX_MINUTES,
            ]);
        } catch (AgentException $e) {
            if ($e->getCode() === self::LIMIT_CODE) {
                return __('Sunucuda aynı anda en fazla :max terminal açık olabilir. Açık olanlardan birini kapatın.', ['max' => self::MAX_SESSIONS]);
            }

            return $e->forPeople();
        }
        Cache::put($this->key($ticket['id']), ['site' => $site->id, 'user' => $user->id], now()->addMinutes(self::MAX_MINUTES + 30));
        AuditLog::record(__('SSH terminali açıldı: :user kullanıcısı (:domain).', ['user' => $account->name, 'domain' => $site->domain]), $user, extra: [
            'section' => 'Siteler',
            'url' => route('sites.terminal', $site, false),
        ]);

        return $ticket;
    }

    /**
     * The account's sessions, newest first, as the agent keeps them.
     *
     * @return list<array<string, mixed>>
     */
    public function sessions(SystemUser $account): array
    {
        try {
            $all = $this->agent->call('terminal.list');
        } catch (AgentException) {
            return [];
        }

        return array_values(array_filter((array) $all, fn ($s) => ($s['user'] ?? null) === $account->name));
    }

    /**
     * Ends a session of the account.
     */
    public function close(SystemUser $account, string $id): void
    {
        foreach ($this->sessions($account) as $session) {
            if ($session['id'] === $id) {
                $this->agent->call('terminal.close', ['id' => $id]);

                return;
            }
        }
    }

    /**
     * Logs the end of a session this panel opened, once; the page calls it
     * when the connection closes. Returns why it ended, for the page.
     */
    public function ended(Site $site, string $id): ?string
    {
        $opened = Cache::get($this->key($id));
        if (! is_array($opened) || $opened['site'] !== $site->id) {
            return null;
        }
        // The browser may hear of the close a moment before the agent has
        // seen the page go.
        for ($try = 0; ; $try++) {
            try {
                $session = $this->agent->call('terminal.get', ['id' => $id]);
            } catch (AgentException) {
                return null;
            }
            if (($session['state'] ?? null) === 'ended') {
                break;
            }
            if ($try >= 5) {
                return null;
            }
            usleep(200_000);
        }
        $reason = self::reason((string) ($session['reason'] ?? ''));
        if (Cache::pull($this->key($id)) !== null) {
            $started = isset($session['started']) ? strtotime($session['started']) : null;
            $ended = isset($session['ended']) ? strtotime($session['ended']) : null;
            $minutes = $started && $ended ? max(1, (int) round(($ended - $started) / 60)) : null;
            AuditLog::record(__('SSH terminali kapandı: :user kullanıcısı (:domain), :minutes dk. :reason.', [
                'user' => $session['user'] ?? '', 'domain' => $site->domain, 'minutes' => $minutes ?? 0, 'reason' => $reason,
            ]), User::find($opened['user']), extra: ['section' => 'Siteler', 'url' => route('sites.terminal', $site, false)]);
        }

        return $reason;
    }

    /**
     * Logs the end of every ended session this panel opened whose page did
     * not (the scheduler, every minute). Returns how many it logged.
     */
    public function sweep(): int
    {
        try {
            $all = (array) $this->agent->call('terminal.list');
        } catch (AgentException) {
            return 0;
        }
        $logged = 0;
        foreach ($all as $session) {
            if (($session['state'] ?? null) !== 'ended') {
                continue;
            }
            $opened = Cache::get($this->key((string) $session['id']));
            $site = is_array($opened) ? Site::find($opened['site']) : null;
            if ($site !== null && $this->ended($site, (string) $session['id']) !== null) {
                $logged++;
            }
        }

        return $logged;
    }

    /**
     * Why a session ended, for people.
     */
    public static function reason(string $code): string
    {
        return match ($code) {
            'left' => __('Sayfa kapatıldı'),
            'idle' => __(':minutes dakika işlem yapılmadı', ['minutes' => self::IDLE_MINUTES]),
            'limit' => __(':minutes dakikalık oturum süresi doldu', ['minutes' => self::MAX_MINUTES]),
            'exited' => __('Kabuktan çıkıldı'),
            'expired', 'failed' => __('Bağlantı kurulamadı'),
            'stopped' => __('Serfex ajanı yeniden başladı'),
            default => __('Oturum kapatıldı'),
        };
    }

    private function key(string $id): string
    {
        return 'terminal:'.$id;
    }
}
