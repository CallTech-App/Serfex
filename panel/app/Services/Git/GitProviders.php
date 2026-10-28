<?php

namespace App\Services\Git;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Setting;
use App\Services\Settings\Notifications;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The Git hosts people may connect their accounts to, set up by an
 * administrator in Araçlar ve Ayarlar → Git sağlayıcıları. Each needs an
 * OAuth application registered on the host with this panel's callback
 * address; the client secret is stored encrypted.
 */
class GitProviders
{
    public const KEY = 'git.providers';

    /** @var array<string, class-string<GitProvider>> */
    public const CLASSES = ['github' => GitHub::class, 'gitlab' => GitLab::class, 'bitbucket' => Bitbucket::class];

    public const LABELS = ['github' => 'GitHub', 'gitlab' => 'GitLab', 'bitbucket' => 'Bitbucket'];

    /**
     * The saved settings of every host; the secret stays encrypted.
     *
     * @return array<string, array{client_id: string, client_secret: ?string, url: string}>
     */
    public function settings(): array
    {
        $saved = (array) Setting::get(self::KEY, []);
        $settings = [];
        foreach (array_keys(self::CLASSES) as $key) {
            $s = (array) ($saved[$key] ?? []);
            $settings[$key] = [
                'client_id' => (string) ($s['client_id'] ?? ''),
                'client_secret' => is_string($s['client_secret'] ?? null) ? $s['client_secret'] : null,
                'url' => (string) ($s['url'] ?? ($key === 'gitlab' ? GitLab::DEFAULT_URL : '')),
            ];
        }

        return $settings;
    }

    /**
     * @param  array{client_id: string, client_secret: ?string, url?: string}  $values  secret null keeps the saved one, '' removes it
     */
    public function save(string $key, array $values): void
    {
        $all = $this->settings();
        $current = $all[$key];
        $all[$key] = [
            'client_id' => trim($values['client_id']),
            'client_secret' => match ($values['client_secret']) {
                null => $current['client_secret'],
                '' => null,
                default => Crypt::encryptString($values['client_secret']),
            },
            'url' => $key === 'gitlab' ? (rtrim(trim($values['url'] ?? ''), '/') ?: GitLab::DEFAULT_URL) : '',
        ];
        Setting::put(self::KEY, $all);
    }

    /**
     * Puts a host's saved settings back as they were (for Undo).
     *
     * @param  array{client_id: string, client_secret: ?string, url: string}  $settings  the secret as stored
     */
    public function restore(string $key, array $settings): void
    {
        $all = $this->settings();
        $all[$key] = [
            'client_id' => (string) ($settings['client_id'] ?? ''),
            'client_secret' => is_string($settings['client_secret'] ?? null) ? $settings['client_secret'] : null,
            'url' => (string) ($settings['url'] ?? ''),
        ];
        Setting::put(self::KEY, $all);
    }

    public function isConfigured(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * The host, when an administrator set it up.
     */
    public function get(string $key): ?GitProvider
    {
        $s = $this->settings()[$key] ?? null;
        if ($s === null || $s['client_id'] === '' || $s['client_secret'] === null) {
            return null;
        }
        try {
            $secret = Crypt::decryptString($s['client_secret']);
        } catch (Throwable) {
            return null;
        }

        return $this->make($key, ['client_id' => $s['client_id'], 'client_secret' => $secret, 'url' => $s['url']]);
    }

    /**
     * @param  array{client_id: string, client_secret: string, url?: string}  $config
     */
    public function make(string $key, array $config): GitProvider
    {
        $class = self::CLASSES[$key];

        return new $class($config);
    }

    /**
     * The hosts set up, by key.
     *
     * @return array<string, GitProvider>
     */
    public function configured(): array
    {
        return array_filter(array_map(fn ($key) => $this->get($key), array_combine(array_keys(self::CLASSES), array_keys(self::CLASSES))));
    }

    /**
     * Where the host sends people back after they allow access; it is
     * entered in the OAuth application on the host.
     */
    public function callbackUrl(string $key): string
    {
        return $this->panelBase().route('git.callback', $key, false);
    }

    /**
     * The address a Git host posts push events to.
     */
    public function webhookUrl(string $token): string
    {
        return $this->panelBase().route('laravel.webhook', $token, false);
    }

    /**
     * The panel's address as the Git hosts reach it: its name and port
     * when it has a name, else the address it was opened at.
     */
    public function panelBase(): string
    {
        if (Setting::get('panel.hostname')) {
            return app(Notifications::class)->panelUrl();
        }

        return app()->runningInConsole() && ! app()->runningUnitTests() ? rtrim((string) config('app.url'), '/') : request()->getSchemeAndHttpHost();
    }

    /**
     * Whether webhooks may insist on a valid certificate: only when the
     * panel has a name and a trusted certificate for it. A panel opened
     * at its IP address has a self-signed one.
     */
    public function panelHasTrustedCertificate(): bool
    {
        return Setting::get('panel.hostname') && Certificate::where('kind', 'panel')->where('status', CertificateStatus::Active)->exists();
    }
}
