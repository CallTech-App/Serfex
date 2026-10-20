<?php

namespace App\Livewire\Settings;

use App\Enums\CertificateStatus;
use App\Models\Certificate;
use App\Models\Setting;
use App\Services\Agent\AgentException;
use App\Services\Settings\PanelSettings;
use App\Services\Sites\SiteDomains;
use App\Services\Ssl\Certificates;
use App\Support\Hostnames;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Araçlar ve Ayarlar → Genel: the panel's address, port, time zone,
 * language and session length, and the panel's own certificate.
 */
#[Layout('components.layouts.app')]
#[Title('Genel ayarlar · Servik')]
class GeneralSettings extends Component
{
    public string $hostname = '';

    public string $timezone = '';

    /** The server's default language. */
    public string $locale = '';

    public string $sessionMinutes = '';

    public bool $changingPort = false;

    public string $port = '';

    public bool $uploading = false;

    public string $certificate = '';

    public string $privateKey = '';

    public string $chain = '';

    public function mount(PanelSettings $settings): void
    {
        $this->hostname = (string) Setting::get('panel.hostname', '');
        $this->timezone = $settings->timezone();
        $this->locale = $settings->locale();
        $this->sessionMinutes = (string) $settings->sessionMinutes();
    }

    public function save(PanelSettings $settings, Certificates $certificates): void
    {
        $this->hostname = Hostnames::normalize($this->hostname);
        $this->sessionMinutes = trim($this->sessionMinutes);
        $this->validate([
            'hostname' => [
                'nullable',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir ad girin, örneğin panel.ornek.com.tr.')),
                fn ($attr, $value, $fail) => $this->usedBySite($value) ? $fail(__('Bu ad bir sitede kullanılıyor; panel için ayrı bir ad seçin, örneğin panel.:value.', ['value' => $value])) : null,
                fn ($attr, $value, $fail) => $value !== '' && $value === Setting::get('mail.hostname') ? $fail(__('Bu ad posta sunucusunun adı; panel için ayrı bir ad seçin.')) : null,
            ],
            'timezone' => ['required', 'string', 'max:64', fn ($attr, $value, $fail) => in_array($value, timezone_identifiers_list(), true) ? null : $fail(__('Listeden bir saat dilimi seçin.'))],
            'locale' => ['required', Rule::in(array_keys(Locales::all()))],
            'sessionMinutes' => ['required', 'integer', 'min:15', 'max:10080'],
        ], [
            'sessionMinutes.min' => __('Oturum süresi en az 15 dakika olabilir.'),
            'sessionMinutes.max' => __('Oturum süresi en fazla 7 gün (10080 dakika) olabilir.'),
        ], ['hostname' => __('panel adresi'), 'timezone' => __('saat dilimi'), 'locale' => __('dil'), 'sessionMinutes' => __('oturum süresi')]);

        $previous = $this->current($settings);
        if ($this->timezone !== $previous['timezone']) {
            try {
                $settings->setTimezone($this->timezone);
            } catch (AgentException $e) {
                $this->addError('timezone', __('Sunucunun saat dilimi değiştirilemedi: :reason', ['reason' => $e->reason]));

                return;
            }
        }
        $settings->setSessionMinutes((int) $this->sessionMinutes);
        $settings->setLocale($this->locale);
        Setting::put('panel.hostname', $this->hostname ?: null);

        // A new address gets its certificate right away; the DNS check
        // keeps Let's Encrypt out of it until the address points here.
        $issuing = $this->hostname !== '' && $this->hostname !== ($previous['hostname'] ?? '') && ! $this->panelBusy();
        if ($issuing) {
            $certificates->issuePanel($this->hostname);
        }

        $message = $issuing ? __('Genel ayarlar kaydedildi. Panel sertifikası alınıyor.') : __('Genel ayarlar kaydedildi.');
        // The page speaks the new language at once when this person goes
        // by the server's default (Undo stays in İşlem günlüğü).
        if (Locales::forUser(auth()->user()) !== app()->getLocale()) {
            Locales::apply(Locales::forUser(auth()->user()));
            session()->flash('toast', $issuing ? __('Genel ayarlar kaydedildi. Panel sertifikası alınıyor.') : __('Genel ayarlar kaydedildi.'));
            $this->redirect(route('settings.general'));

            return;
        }
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => 'undoSave', 'args' => [$previous]]);
    }

    /**
     * @param  array{hostname: ?string, timezone: string, locale?: string, session: int}  $previous
     */
    public function undoSave(PanelSettings $settings, array $previous): void
    {
        $timezone = (string) ($previous['timezone'] ?? '');
        if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true) && $timezone !== $settings->timezone()) {
            try {
                $settings->setTimezone($timezone);
            } catch (AgentException $e) {
                $this->dispatch('toast', message: __('Saat dilimi geri alınamadı: :reason', ['reason' => $e->reason]));

                return;
            }
        }
        $settings->setSessionMinutes(max(15, min(10080, (int) ($previous['session'] ?? PanelSettings::DEFAULT_SESSION_MINUTES))));
        if (Locales::supports($previous['locale'] ?? null)) {
            $settings->setLocale($previous['locale']);
        }
        $hostname = Hostnames::normalize((string) ($previous['hostname'] ?? ''));
        Setting::put('panel.hostname', $hostname !== '' && Hostnames::isValidDomain($hostname) ? $hostname : null);
        $this->mount($settings);
        $this->resetValidation();
        $this->dispatch('toast', message: __('Genel ayarlardaki değişiklik geri alındı.'));
    }

    public function openPort(PanelSettings $settings): void
    {
        $this->resetValidation('port');
        $this->port = '';
        $this->changingPort = $settings->portChange() === null;
    }

    public function savePort(PanelSettings $settings): void
    {
        $this->resetValidation('port');
        $port = trim($this->port);
        $problem = ctype_digit($port) ? $settings->portProblem((int) $port) : __('Bir port numarası girin, örneğin 9443.');
        if ($problem !== null) {
            $this->addError('port', $problem);

            return;
        }
        try {
            $settings->changePort((int) $port);
        } catch (AgentException $e) {
            $this->addError('port', $e->getCode() === AgentException::INVALID_PARAMS ? __('Bu port kullanılamıyor: :reason', ['reason' => $e->reason]) : __('Port değiştirilemedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->changingPort = false;
        $this->dispatch('toast', message: __('Panel :port portunda da açıldı. Yeni adresten açıp onaylayın.', ['port' => $port]));
    }

    /**
     * Keeps the new port. Only from the new port itself: that shows the
     * port is reachable for the person confirming.
     */
    public function acceptPort(PanelSettings $settings): void
    {
        $change = $settings->portChange();
        if ($change === null) {
            return;
        }
        if (request()->getPort() !== (int) $change['to']) {
            $this->dispatch('toast', message: __('Onayı yeni porttan verin; böylece yeni portun size açık olduğu görülür.'));

            return;
        }
        try {
            $settings->confirmPort();
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Port değiştirilemedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast', message: __('Panel artık yalnızca :to portunda.', ['to' => $change['to']]), source: $this->getId(), undo: ['method' => 'portBack', 'args' => [(int) $change['from']]]);
    }

    public function revertPort(PanelSettings $settings): void
    {
        $change = $settings->portChange();
        if ($change === null) {
            return;
        }
        try {
            $settings->revertPort();
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Eski porta dönülemedi: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast', message: __('Port değişikliğinden vazgeçildi; panel :from portunda kaldı.', ['from' => $change['from']]));
    }

    /**
     * Undo of a confirmed change: the previous port, again with the
     * confirmation window.
     */
    public function portBack(PanelSettings $settings, int $port): void
    {
        if ($settings->portChange() !== null || $settings->portProblem($port) !== null) {
            return;
        }
        try {
            $settings->changePort($port);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: __('Eski port açılamadı: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->dispatch('toast', message: __('Panel yeniden :port portunda da açıldı; eski adresten onaylayın.', ['port' => $port]));
    }

    public function issuePanel(Certificates $certificates): void
    {
        $hostname = (string) Setting::get('panel.hostname', '');
        if ($hostname === '' || $this->panelBusy()) {
            return;
        }
        $certificates->issuePanel($hostname);
        $this->dispatch('toast', message: __('Panel sertifikası alınıyor.'));
    }

    public function openUpload(): void
    {
        $this->resetValidation();
        $this->certificate = $this->privateKey = $this->chain = '';
        $this->uploading = true;
    }

    public function uploadCertificate(PanelSettings $settings): void
    {
        $this->validate([
            'certificate' => ['required', 'string', 'max:65536', 'regex:/-----BEGIN CERTIFICATE-----/'],
            'privateKey' => ['required', 'string', 'max:65536', 'regex:/-----BEGIN [A-Z ]*PRIVATE KEY-----/'],
            'chain' => ['nullable', 'string', 'max:131072'],
        ], [
            'certificate.regex' => __('PEM biçiminde bir sertifika yapıştırın (-----BEGIN CERTIFICATE----- ile başlar).'),
            'privateKey.regex' => __('PEM biçiminde bir özel anahtar yapıştırın (-----BEGIN PRIVATE KEY----- ile başlar).'),
        ], ['certificate' => 'sertifika', 'privateKey' => __('özel anahtar'), 'chain' => __('ara sertifikalar')]);
        try {
            $settings->uploadCertificate($this->certificate, $this->privateKey, $this->chain);
        } catch (AgentException $e) {
            $this->addError('certificate', __('Sertifika kurulamadı: :reason', ['reason' => $e->reason]));

            return;
        }
        $this->uploading = false;
        $this->certificate = $this->privateKey = $this->chain = '';
        // Undo goes back to Let's Encrypt for the panel's address.
        $this->dispatch('toast', message: __('Panelin kendi sertifikası kuruldu.'), source: $this->getId(),
            undo: Setting::get('panel.hostname') ? ['method' => 'issuePanel', 'args' => []] : null);
    }

    public function render(PanelSettings $settings): View
    {
        return view('livewire.settings.general', [
            'panel' => Certificate::where('kind', 'panel')->first(),
            'savedHostname' => (string) Setting::get('panel.hostname', ''),
            'portNow' => $settings->port(),
            'portChange' => $settings->portChange(),
            'requestPort' => request()->getPort(),
            'timezones' => $this->timezoneOptions($settings),
        ]);
    }

    /**
     * @return list<string>
     */
    private function timezoneOptions(PanelSettings $settings): array
    {
        $zones = array_values(array_intersect($settings->timezones(), timezone_identifiers_list()));
        if (! in_array($this->timezone, $zones, true)) {
            array_unshift($zones, $this->timezone);
        }

        return $zones;
    }

    /**
     * @return array{hostname: ?string, timezone: string, locale: string, session: int}
     */
    private function current(PanelSettings $settings): array
    {
        return [
            'hostname' => Setting::get('panel.hostname'),
            'timezone' => $settings->timezone(),
            'locale' => $settings->locale(),
            'session' => $settings->sessionMinutes(),
        ];
    }

    private function panelBusy(): bool
    {
        return Certificate::where('kind', 'panel')->where('status', CertificateStatus::Issuing)->exists();
    }

    private function usedBySite(string $hostname): bool
    {
        return app(SiteDomains::class)->siteFor($hostname) !== null;
    }
}
