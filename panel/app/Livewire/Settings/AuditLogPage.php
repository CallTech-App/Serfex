<?php

namespace App\Livewire\Settings;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Araçlar ve Ayarlar → İşlem günlüğü: what was done through the panel,
 * the API and Serfex AI, by whom and when, with Undo while it applies.
 */
#[Layout('components.layouts.app')]
#[Title('İşlem günlüğü · Serfex')]
class AuditLogPage extends Component
{
    use WithPagination;

    #[Url(as: 'ara', except: '')]
    public string $search = '';

    #[Url(as: 'kim', except: '')]
    public string $who = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedWho(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower(trim($this->search))).'%';
        $entries = AuditEntry::query()
            ->when(trim($this->search) !== '', fn ($q) => $q->where(fn ($q) => $q->whereRaw('lower(message) like ?', [$term])
                ->orWhereRaw('lower(section) like ?', [$term])->orWhereRaw('lower(actor) like ?', [$term])))
            ->when($this->who !== '', fn ($q) => match (true) {
                $this->who === 'api', $this->who === 'ai' => $q->where('via', $this->who),
                $this->who === 'customers' => $q->whereNotNull('customer_id'),
                ctype_digit($this->who) => $q->where('user_id', (int) $this->who),
                default => $q,
            })
            ->latest('id')
            ->paginate(50);

        return view('livewire.settings.audit', [
            'entries' => $entries,
            'admins' => User::where('is_admin', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
