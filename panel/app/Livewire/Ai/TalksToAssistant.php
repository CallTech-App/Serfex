<?php

namespace App\Livewire\Ai;

use App\Models\AiAction;
use App\Models\AiConversation;
use App\Services\Ai\Actions;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Assistant;
use App\Services\Monitor\MonitorException;

/**
 * Asking Servik AI and deciding about its proposals, shared by the Servik
 * AI page and the drawer behind the top-bar button.
 */
trait TalksToAssistant
{
    public ?int $conversationId = null;

    public string $question = '';

    protected function conversation(): ?AiConversation
    {
        return $this->conversationId === null ? null
            : AiConversation::where('user_id', auth()->id())->find($this->conversationId);
    }

    public function send(Assistant $assistant, AiSettings $settings): void
    {
        $text = trim($this->question);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > 4000) {
            $this->addError('question', __('Mesaj en fazla 4000 karakter olabilir.'));

            return;
        }
        $conversation = $this->conversation();
        if (! $settings->ready() || $conversation?->isRunning()) {
            return;
        }
        $conversation = $assistant->ask(auth()->user(), $text, $conversation, request()->ip());
        $this->conversationId = $conversation->id;
        $this->question = '';
        $this->resetValidation();
        $this->dispatch('ai-scroll');
    }

    /**
     * Starts a new conversation with a question, for "Bunu AI'ya sor".
     */
    public function askNew(string $text, Assistant $assistant, AiSettings $settings): void
    {
        $this->conversationId = null;
        $this->question = mb_substr($text, 0, 4000);
        $this->send($assistant, $settings);
    }

    public function startNew(): void
    {
        $this->conversationId = null;
        $this->question = '';
        $this->resetValidation();
    }

    public function retry(Assistant $assistant): void
    {
        $conversation = $this->conversation();
        if ($conversation?->status === 'failed') {
            $assistant->retry($conversation);
        }
    }

    public function apply(int $id, Actions $actions): void
    {
        $action = $this->action($id);
        if ($action === null) {
            return;
        }
        try {
            $actions->apply($action, auth()->user());
        } catch (MonitorException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: __('Uygulandı: :title.', ['title' => $action->title]), source: $this->getId(),
            undo: $actions->canUndo($action->refresh()) ? ['method' => 'undo', 'args' => [$action->id]] : null);
    }

    public function reject(int $id, Actions $actions): void
    {
        $action = $this->action($id);
        if ($action !== null) {
            $actions->reject($action, auth()->user());
            $this->dispatch('toast', message: __('Öneri uygulanmadı.'));
        }
    }

    public function undo(int $id, Actions $actions): void
    {
        $action = $this->action($id);
        if ($action === null) {
            return;
        }
        try {
            $actions->undo($action, auth()->user());
        } catch (MonitorException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: __('Geri alındı: :title.', ['title' => $action->title]));
    }

    private function action(int $id): ?AiAction
    {
        return AiAction::whereHas('conversation', fn ($q) => $q->where('user_id', auth()->id()))->find($id);
    }
}
