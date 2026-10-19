<?php

namespace App\Livewire\Ai;

use App\Models\AiConversation;
use App\Services\Ai\Actions;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Transcript;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The Servik AI page: earlier conversations on the left, the open one on
 * the right.
 */
#[Layout('components.layouts.app')]
#[Title('Servik AI · Servik')]
class AiAssistant extends Component
{
    use TalksToAssistant;

    #[Url(as: 'sohbet', except: null)]
    public ?int $conversationId = null;

    public function mount(): void
    {
        // Salt okunur accounts do not use Servik AI: it acts and it costs.
        abort_if(auth()->user()->isReadOnly(), 403);
        if ($this->conversationId !== null && $this->conversation() === null) {
            $this->conversationId = null;
        }
    }

    public function open(int $id): void
    {
        $this->conversationId = AiConversation::where('user_id', auth()->id())->find($id)?->id;
        $this->resetValidation();
        $this->dispatch('ai-scroll');
    }

    public function delete(int $id): void
    {
        $conversation = AiConversation::where('user_id', auth()->id())->find($id);
        if ($conversation === null) {
            return;
        }
        $conversation->delete();
        if ($this->conversationId === $id) {
            $this->conversationId = null;
        }
        $this->dispatch('toast', message: 'Sohbet silindi.', source: $this->getId(), undo: ['method' => 'restore', 'args' => [$id]]);
    }

    public function restore(int $id): void
    {
        AiConversation::onlyTrashed()->where('user_id', auth()->id())->find($id)?->restore();
        $this->conversationId = $id;
    }

    public function render(AiSettings $settings, Actions $actions): View
    {
        $conversation = $this->conversation();

        return view('livewire.ai.index', [
            'conversation' => $conversation,
            'conversations' => AiConversation::where('user_id', auth()->id())->latest('updated_at')->limit(50)->get(['id', 'title', 'updated_at', 'status']),
            'items' => $conversation ? Transcript::items($conversation) : [],
            'ready' => $settings->ready(),
            'hasKey' => $settings->apiKey() !== null,
            'actions' => $actions,
        ]);
    }
}
