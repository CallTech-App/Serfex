<?php

namespace App\Livewire\Ssl;

use App\Enums\CertificateStatus;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Certificate;
use App\Models\Site;
use App\Services\Ssl\Certificates;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every site on the server (a customer's own) with the state of its
 * certificate.
 */
#[Layout('components.layouts.app')]
#[Title('SSL Sertifikaları · Servik')]
#[ForCustomers]
class SslIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** "" for all sites, "problem" for the ones that need attention. */
    #[Url(as: 'durum', except: '')]
    public string $filter = '';

    /** @var list<int> */
    public array $selected = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Request Let's Encrypt certificates for the selected sites (renew
     * the ones that already have one).
     */
    public function issueSelected(Certificates $certificates): void
    {
        $count = 0;
        foreach (Site::visibleTo(auth()->user())->with('certificate', 'certificates')->whereKey($this->selected)->get() as $site) {
            if ($site->status->isBusy() || $site->certificates->contains('status', CertificateStatus::Issuing)) {
                continue;
            }
            if ($site->certificate !== null && ! $site->certificate->isLetsEncrypt()) {
                // An uploaded certificate is replaced only from the site's own page.
                continue;
            }
            $certificate = $site->certificate;
            if ($certificate !== null) {
                $certificates->reissue($certificate);
            } else {
                $certificates->letsEncrypt($site);
            }
            $count++;
        }
        $this->selected = [];
        $this->dispatch('toast', message: match ($count) {
            0 => 'Seçili sitelerde alınacak sertifika yok; yüklenmiş sertifikalar kendi sayfasından değiştirilir.',
            1 => "Let's Encrypt sertifikası alınıyor.",
            default => "{$count} site için Let's Encrypt sertifikası alınıyor.",
        });
    }

    public function render(): View
    {
        $user = auth()->user();
        $sites = Site::visibleTo($user)
            ->with('certificate', 'certificates')
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
                $q->where('domain', 'like', $term);
            })
            ->when($this->filter === 'problem', fn (Builder $q) => $q->where(fn (Builder $q) => $q
                ->whereNull('certificate_id')
                ->orWhereHas('certificate', fn (Builder $q) => $q->needsAttention())))
            ->orderBy('domain')
            ->paginate(25);

        // The panel's and the mail server's own certificates are the
        // administrator's business.
        $admin = $user->is_admin;

        return view('livewire.ssl.index', [
            'sites' => $sites,
            'busy' => $sites->getCollection()->contains(fn (Site $s) => $s->sslState()->isBusy()),
            'attention' => $admin ? Certificate::attentionCount() : Certificate::attentionCount($user),
            'panel' => $admin ? Certificate::where('kind', 'panel')->first() : null,
            'mail' => $admin ? Certificate::where('kind', 'mail')->first() : null,
        ]);
    }
}
