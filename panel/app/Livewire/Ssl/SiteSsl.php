<?php

namespace App\Livewire\Ssl;

use App\Enums\CertificateStatus;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Certificate;
use App\Models\Site;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Ssl\Certificates;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * The SSL tool inside a site: its certificate, Let's Encrypt or an upload,
 * and the HTTP to HTTPS redirect.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteSsl extends Component
{
    public int $siteId;

    public bool $requesting = false;

    public bool $includeAliases = true;

    public bool $wildcard = false;

    public bool $uploading = false;

    public string $certificatePem = '';

    public string $privateKeyPem = '';

    public string $chainPem = '';

    public bool $confirmingRemove = false;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    public function openRequest(): void
    {
        $this->reset('includeAliases', 'wildcard');
        $this->requesting = true;
    }

    public function requestCertificate(Certificates $certificates): void
    {
        $site = $this->site();
        if ($site === null || $this->busy($site)) {
            return;
        }
        $wildcard = $this->wildcard && $certificates->zoneFor($site->domain) !== null;
        if (! $this->includeAliases && ! $wildcard) {
            // Only the domain itself: drop the other names from this request.
            $site->setAttribute('aliases', []);
            $site->setRelation('subdomains', collect());
            $site->setRelation('redirects', collect());
        }
        $certificate = $certificates->letsEncrypt($site, $wildcard);
        $this->requesting = false;
        $this->dispatch('toast', message: $certificate->isInstalled()
            ? __(':domain sertifikası yeniden alınıyor.', ['domain' => $site->domain])
            : __(':domain için Let\'s Encrypt sertifikası alınıyor.', ['domain' => $site->domain]));
    }

    public function renew(Certificates $certificates): void
    {
        $certificate = $this->site()?->certificate;
        if ($certificate === null || ! $certificate->isLetsEncrypt() || $certificate->status === CertificateStatus::Issuing) {
            return;
        }
        $certificates->reissue($certificate);
        $this->dispatch('toast', message: __('Sertifika yenileniyor.'));
    }

    public function openUpload(): void
    {
        $this->reset('certificatePem', 'privateKeyPem', 'chainPem');
        $this->resetValidation();
        $this->uploading = true;
    }

    public function upload(Certificates $certificates, AgentClient $agent): void
    {
        $site = $this->site();
        if ($site === null || $this->busy($site)) {
            return;
        }
        $this->validate([
            'certificatePem' => ['required', 'string', 'max:65536'],
            'privateKeyPem' => ['required', 'string', 'max:32768'],
            'chainPem' => ['nullable', 'string', 'max:65536'],
        ], [], ['certificatePem' => 'sertifika', 'privateKeyPem' => __('özel anahtar'), 'chainPem' => __('ara sertifikalar')]);

        $previous = $site->certificate_id;
        try {
            $certificates->upload($site, $agent, $this->certificatePem, $this->privateKeyPem, $this->chainPem);
        } catch (AgentException $e) {
            $this->addError('certificatePem', $e instanceof AgentUnavailableException
                ? __('Sunucu ajanına ulaşılamıyor. Sunucuda "systemctl status serfex-agent" ile kontrol edin.')
                : self::uploadError($e->reason));

            return;
        }
        $this->reset('certificatePem', 'privateKeyPem', 'chainPem');
        $this->uploading = false;
        $this->dispatch('toast',
            message: __('Sertifika yüklendi, site HTTPS ayarları uygulanıyor.'),
            source: $this->getId(),
            undo: ['method' => 'undoSwap', 'args' => [$previous]],
        );
    }

    public function setRedirect(bool $on, Certificates $certificates): void
    {
        $site = $this->site();
        if ($site === null || $this->busy($site) || $site->https_redirect === $on) {
            return;
        }
        $certificates->setRedirect($site, $on);
        $this->dispatch('toast',
            message: $on ? __('HTTP istekleri HTTPS\'e yönlendirilecek.') : __('HTTP yönlendirmesi kapatıldı.'),
            source: $this->getId(),
            undo: ['method' => 'setRedirect', 'args' => [! $on]],
        );
    }

    public function setAutoRenew(bool $on): void
    {
        $certificate = $this->site()?->certificate;
        if (! $certificate?->isLetsEncrypt() || $certificate->auto_renew === $on) {
            return;
        }
        $certificate->update(['auto_renew' => $on]);
        $this->dispatch('toast',
            message: $on ? __('Otomatik yenileme açıldı.') : __('Otomatik yenileme kapatıldı; sertifika süresi dolunca site HTTPS hatası verir.'),
            source: $this->getId(),
            undo: ['method' => 'setAutoRenew', 'args' => [! $on]],
        );
    }

    public function remove(Certificates $certificates): void
    {
        $site = $this->site();
        $this->confirmingRemove = false;
        if ($site === null || $site->certificate_id === null || $this->busy($site)) {
            return;
        }
        $previous = $site->certificate_id;
        $certificates->use($site, null);
        $this->dispatch('toast',
            message: __('Sertifika kaldırıldı; site yalnızca HTTP ile açılacak.'),
            source: $this->getId(),
            undo: ['method' => 'undoSwap', 'args' => [$previous]],
        );
    }

    /**
     * Undo for upload and remove: go back to the certificate used before,
     * while the server still has it.
     */
    public function undoSwap(?int $previous, Certificates $certificates): void
    {
        $site = $this->site();
        if ($site === null) {
            return;
        }
        $certificate = $previous === null ? null : $site->certificates()->find($previous);
        if ($previous !== null && $certificate === null) {
            $this->dispatch('toast', message: __('Önceki sertifika artık sunucuda değil; geri alınamadı.'));

            return;
        }
        $certificates->use($site, $certificate);
        $this->dispatch('toast', message: __('Geri alındı.'));
    }

    public function render(Certificates $certificates): View
    {
        $site = $this->site();
        if ($site === null) {
            return view('livewire.sites.gone');
        }
        $certificate = $site->certificate;
        // A request that has not produced a certificate yet.
        $pending = $site->certificates()
            ->whereKeyNot($site->certificate_id ?? 0)
            ->whereIn('status', [CertificateStatus::Issuing, CertificateStatus::WaitingDns, CertificateStatus::Failed])
            ->latest('id')
            ->first();

        return view('livewire.ssl.site', [
            'site' => $site,
            'certificate' => $certificate,
            'pending' => $pending,
            'zone' => $certificates->zoneFor($site->domain),
            'busy' => $site->status->isBusy() || $certificate?->status === CertificateStatus::Issuing || $pending?->status === CertificateStatus::Issuing,
        ])->title('SSL · '.$site->domain.' · Serfex');
    }

    private function site(): ?Site
    {
        return Site::visibleTo(auth()->user())->with('certificate')->find($this->siteId);
    }

    private function busy(Site $site): bool
    {
        return $site->status->isBusy();
    }

    /**
     * Turn the agent's reason into a sentence for the upload form.
     */
    public static function uploadError(string $reason): string
    {
        return match (true) {
            str_contains($reason, 'does not belong') => __('Özel anahtar bu sertifikaya ait değil.'),
            str_contains($reason, 'expired') => __('Sertifikanın süresi dolmuş.'),
            str_contains($reason, 'does not cover') => __('Sertifika bu alan adını kapsamıyor. :reason', ['reason' => $reason]),
            str_contains($reason, 'password') => __('Özel anahtar parolalı; parolasız halini yükleyin.'),
            str_contains($reason, 'no PEM certificate') => __('Sertifika PEM biçiminde değil (-----BEGIN CERTIFICATE----- ile başlamalı).'),
            str_contains($reason, 'no PEM private key') => __('Özel anahtar PEM biçiminde değil (-----BEGIN PRIVATE KEY----- ile başlamalı).'),
            default => __('Sertifika kurulamadı: :reason', ['reason' => $reason]),
        };
    }
}
