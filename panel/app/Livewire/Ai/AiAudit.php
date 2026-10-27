<?php

namespace App\Livewire\Ai;

use App\Models\AiEvent;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Serfex AI's audit log: everything it read, proposed and did, and what
 * people decided, newest first.
 */
#[Layout('components.layouts.app')]
#[Title('Serfex AI kaydı · Serfex')]
class AiAudit extends Component
{
    use WithPagination;

    #[Url(as: 'tur', except: '')]
    public string $type = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render(): View
    {
        $type = isset(AiEvent::TYPES[$this->type]) || $this->type === 'changes' ? $this->type : '';
        $search = trim($this->search);

        return view('livewire.ai.audit', [
            'events' => AiEvent::with('user', 'conversation')
                ->when($type === 'changes', fn ($q) => $q->where('type', '!=', 'read'))
                ->when($type !== '' && $type !== 'changes', fn ($q) => $q->where('type', $type))
                ->when($search !== '', fn ($q) => $q->where('summary', 'ilike', '%'.addcslashes($search, '%_\\').'%'))
                ->latest('created_at')->latest('id')
                ->paginate(50),
        ]);
    }
}
