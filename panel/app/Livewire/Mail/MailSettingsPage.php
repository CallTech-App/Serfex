<?php

namespace App\Livewire\Mail;

use App\Enums\CertificateStatus;
use App\Jobs\SetupMailServer;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Mail\MailSettings;
use App\Services\Sites\SiteDomains;
use App\Services\Ssl\Certificates;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Server-wide mail settings: the mail server's name, defaults for new
 * mailboxes, the sending limit, webmail, and the server's certificate.
 */
#[Layout('components.layouts.app')]
#[Title('E-posta ayarları · Serfex')]
class MailSettingsPage extends Component
{
    use ChecksMailServer;

    public string $hostname = '';

    public int $defaultQuota = 1024;

    public int $hourlyLimit = 500;

    /** roundcube, snappymail or off. */
    public string $webmail = 'roundcube';

    public bool $auto = true;

    public function mount(MailSettings $settings, AgentClient $agent): void
    {
        $this->fill([
            'hostname' => $settings->hostname() ?? $settings->suggestedHostname(),
            'defaultQuota' => $settings->defaultQuotaMb(),
            'hourlyLimit' => $settings->hourlyLimit(),
            'webmail' => $settings->webmail() ? $settings->webmailApp() : 'off',
            'auto' => $settings->autoEnable(),
        ]);
        $this->refreshServer($agent, $settings);
    }

    public function save(MailSettings $settings): void
    {
        $this->hostname = Hostnames::normalize($this->hostname);
        $this->validate([
            'hostname' => [
                'required',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) && substr_count($value, '.') >= 2 ? null : $fail(__('Tam bir ad girin, örneğin mail.ornek.com.tr.')),
                fn ($attr, $value, $fail) => $value === Setting::get('panel.hostname') ? $fail(__('Bu ad panelin adresi; posta sunucusu için ayrı bir ad seçin, örneğin mail.:value.', ['value' => preg_replace('/^[^.]+\./', '', $value)])) : null,
                fn ($attr, $value, $fail) => $this->usedBySite($value) ? $fail(__('Bu ad bir sitede kullanılıyor; posta sunucusu için ayrı bir ad seçin.')) : null,
            ],
            'defaultQuota' => ['required', 'integer', Rule::in(array_keys(MailSettings::QUOTAS))],
            'hourlyLimit' => ['required', 'integer', 'min:0', 'max:100000'],
            'webmail' => ['required', Rule::in([...array_keys(MailSettings::WEBMAIL_APPS), 'off'])],
        ], [], [
            'hostname' => __('posta sunucusunun adı'),
            'defaultQuota' => __('varsayılan kota'),
            'hourlyLimit' => __('saatlik gönderim sınırı'),
        ]);

        $previous = $this->current($settings);
        $settings->save([
            'hostname' => $this->hostname,
            'default_quota_mb' => $this->defaultQuota,
            'hourly_limit' => $this->hourlyLimit,
            'webmail' => $this->webmail !== 'off',
            // Turned off, the application chosen before is kept.
            'webmail_app' => $this->webmail === 'off' ? $settings->webmailApp() : $this->webmail,
            'auto' => $this->auto,
        ]);
        $applied = $this->applyToServer($settings, $previous);

        $this->dispatch('toast',
            message: $applied ? __('E-posta ayarları kaydedildi. Posta sunucusu yeni ayarlarla yeniden yapılandırılıyor.') : __('E-posta ayarları kaydedildi.'),
            source: $this->getId(),
            undo: ['method' => 'undoSave', 'args' => [$previous]],
        );
    }

    /**
     * @param  array<string, mixed>  $previous
     */
    public function undoSave(array $previous, MailSettings $settings, AgentClient $agent): void
    {
        $now = $this->current($settings);
        $settings->save($previous);
        $this->applyToServer($settings, $now);
        $this->mount($settings, $agent);
        $this->resetValidation();
        $this->dispatch('toast', message: __('Değişiklik geri alındı.'));
    }

    public function issueCertificate(MailSettings $settings, Certificates $certificates): void
    {
        $hostname = $settings->hostname();
        if ($hostname === null || $settings->certificate()?->status === CertificateStatus::Issuing) {
            return;
        }
        $certificates->issueMail($hostname);
        $this->dispatch('toast', message: __('Posta sunucusunun sertifikası alınıyor.'));
    }

    public function render(MailSettings $settings): View
    {
        return view('livewire.mail.settings', [
            'certificate' => $settings->certificate(),
            'savedHostname' => $settings->hostname(),
            'setup' => $settings->server(),
            'quotas' => MailSettings::QUOTAS,
        ]);
    }

    /**
     * Reconfigure the mail server when a setting it uses changed.
     *
     * @param  array<string, mixed>  $previous
     */
    private function applyToServer(MailSettings $settings, array $previous): bool
    {
        $now = $this->current($settings);
        $changed = $now['hostname'] !== $previous['hostname'] || $now['hourly_limit'] !== $previous['hourly_limit'] || $now['webmail'] !== $previous['webmail']
            || ($now['webmail'] && $now['webmail_app'] !== $previous['webmail_app']);
        if (! $changed || ! $settings->isSetUp() || ($settings->server()['state'] ?? null) === 'installing') {
            return false;
        }
        $settings->saveServer(['state' => 'installing', 'error' => null]);
        $this->server = ['state' => 'installing'];
        SetupMailServer::dispatch();

        return true;
    }

    /**
     * @return array{hostname: ?string, default_quota_mb: int, hourly_limit: int, webmail: bool, webmail_app: string, auto: bool}
     */
    private function current(MailSettings $settings): array
    {
        return [
            'hostname' => $settings->hostname(),
            'default_quota_mb' => $settings->defaultQuotaMb(),
            'hourly_limit' => $settings->hourlyLimit(),
            'webmail' => $settings->webmail(),
            'webmail_app' => $settings->webmailApp(),
            'auto' => $settings->autoEnable(),
        ];
    }

    private function usedBySite(string $hostname): bool
    {
        return app(SiteDomains::class)->siteFor($hostname) !== null;
    }
}
