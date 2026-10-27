<?php

namespace App\Livewire\Ai;

use App\Models\AiConversation;
use App\Services\Ai\Actions;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Assistant;
use App\Services\Ai\Transcript;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The drawer behind the top-bar Serfex AI button: the newest conversation,
 * and where "Bunu AI'ya sor" buttons send their question.
 */
class AiDrawer extends Component
{
    use TalksToAssistant;

    public bool $open = false;

    public function mount(): void
    {
        $this->conversationId = AiConversation::where('user_id', auth()->id())
            ->where('updated_at', '>=', now()->subHours(12))->latest('updated_at')->value('id');
    }

    /**
     * A "Bunu AI'ya sor" button: opens the drawer on a new conversation.
     */
    #[On('ai-ask')]
    public function onAsk(string $text, Assistant $assistant, AiSettings $settings): void
    {
        $this->open = true;
        $this->askNew($text, $assistant, $settings);
    }

    public function render(AiSettings $settings, Actions $actions): View
    {
        $conversation = $this->open ? $this->conversation() : null;

        return view('livewire.ai.drawer', [
            'conversation' => $conversation,
            'items' => $conversation ? Transcript::items($conversation) : [],
            'ready' => $settings->ready(),
            'hasKey' => $settings->apiKey() !== null,
            'actions' => $actions,
        ]);
    }
}
