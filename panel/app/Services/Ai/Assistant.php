<?php

namespace App\Services\Ai;

use App\Jobs\RunAssistantTurn;
use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\AiMessage;
use App\Models\AiUsage;
use App\Models\User;
use App\Support\Locales;
use Illuminate\Support\Str;

/**
 * Serfex AI's conversations: asking, and the loop that lets Claude call
 * the panel's tools until it has an answer.
 */
class Assistant
{
    public function __construct(
        private readonly ClaudeClient $claude,
        private readonly Tools $tools,
        private readonly AiSettings $settings,
    ) {}

    /**
     * Adds a question to a conversation (a new one when null) and queues
     * the answer.
     */
    public function ask(User $user, string $text, ?AiConversation $conversation = null, ?string $clientIp = null): AiConversation
    {
        $text = trim($text);
        $conversation ??= AiConversation::create([
            'user_id' => $user->id,
            'title' => Str::limit(preg_replace('/\s+/', ' ', $text), 80, '…'),
        ]);
        $conversation->update(['status' => 'running', 'error' => null, 'client_ip' => $clientIp ?? $conversation->client_ip]);

        $context = 'Panel context (not typed by the administrator): now '.now()->timezone(config('serfex.timezone'))->format('Y-m-d H:i T')
            .', administrator '.$user->name.($clientIp ? ', connected from '.$clientIp : '')
            .', answer in '.(Locales::forUser($user) === 'en' ? 'English' : 'Turkish').'.';
        $notes = $this->decisionNotes($conversation);
        $conversation->messages()->create([
            'kind' => 'user',
            'text' => $text,
            'content' => [
                ['type' => 'text', 'text' => $context.($notes !== '' ? "\n".$notes : '')],
                ['type' => 'text', 'text' => $text],
            ],
        ]);
        RunAssistantTurn::dispatch($conversation);

        return $conversation;
    }

    /**
     * Runs the conversation again after a failure.
     */
    public function retry(AiConversation $conversation): void
    {
        $conversation->update(['status' => 'running', 'error' => null]);
        RunAssistantTurn::dispatch($conversation);
    }

    /**
     * Lets Claude answer, calling tools until it stops or the step limit
     * is reached.
     */
    public function run(AiConversation $conversation): void
    {
        $model = $this->settings->model();
        try {
            $this->repairHistory($conversation);
            for ($step = 0; $step < (int) config('serfex.ai.max_steps'); $step++) {
                if (! $this->settings->ready()) {
                    throw new ClaudeException(__('Serfex AI kapalı ya da Claude API anahtarı kayıtlı değil.'));
                }
                if ($this->settings->overBudget()) {
                    throw new ClaudeException(__('Bu ayın Serfex AI harcama sınırına ulaşıldı. Sınırı Serfex AI ayarlarından değiştirebilirsiniz.'));
                }
                $messages = $conversation->messages()->get()->map(fn (AiMessage $m) => self::forApi($m->toApi()))->all();
                $response = $this->claude->send($model, [['type' => 'text', 'text' => Prompt::system()]], $this->tools->definitions(), $messages);
                $this->recordUsage($conversation, $response['model'], $response['usage']);

                $answer = $conversation->messages()->create([
                    'kind' => 'assistant',
                    'content' => $response['content'],
                    'model' => $response['model'],
                    'stop_reason' => $response['stop_reason'],
                ]);
                if ($response['stop_reason'] !== 'tool_use') {
                    $conversation->update(['status' => 'idle']);

                    return;
                }

                $results = [];
                foreach ($answer->toolUses() as $use) {
                    [$result, $error] = $this->tools->run((string) $use['name'], (array) ($use['input'] ?? []), (string) $use['id'], $conversation);
                    $results[] = ['type' => 'tool_result', 'tool_use_id' => $use['id'], 'content' => $result] + ($error ? ['is_error' => true] : []);
                }
                $conversation->messages()->create(['kind' => 'tool_results', 'content' => $results]);
                $conversation->touch();
            }
            throw new ClaudeException(__('Serfex AI bu soru için çok fazla adım attı ve durduruldu. Soruyu daraltıp yeniden deneyin.'));
        } catch (ClaudeException $e) {
            $conversation->update(['status' => 'failed', 'error' => $e->getMessage()]);
        }
    }

    /**
     * A conversation interrupted between a tool call and its result gets
     * the missing results, so the history stays valid.
     */
    private function repairHistory(AiConversation $conversation): void
    {
        $last = $conversation->messages()->reorder('id', 'desc')->first();
        if ($last?->kind !== 'assistant' || $last->toolUses() === []) {
            return;
        }
        $conversation->messages()->create(['kind' => 'tool_results', 'content' => array_map(fn ($u) => [
            'type' => 'tool_result', 'tool_use_id' => $u['id'], 'content' => 'Interrupted before this tool ran.', 'is_error' => true,
        ], $last->toolUses())]);
    }

    /**
     * What the administrator decided about proposals since the last
     * question, so Claude knows what was applied.
     */
    private function decisionNotes(AiConversation $conversation): string
    {
        $since = $conversation->messages()->where('kind', 'user')->reorder('id', 'desc')->value('created_at');
        if ($since === null) {
            return '';
        }
        $labels = ['applied' => 'applied', 'rejected' => 'cancelled', 'undone' => 'applied, then undone', 'failed' => 'failed'];

        return $conversation->actions()->where('updated_at', '>=', $since)->whereIn('status', array_keys($labels))->get()
            ->map(fn (AiAction $a) => "Since the last question \"{$a->title}\" was {$labels[$a->status]}".($a->error ? " ({$a->error})" : '').'.')
            ->implode("\n");
    }

    /**
     * @param  array{input: int, output: int, cache_read: int, cache_write: int}  $usage
     */
    private function recordUsage(AiConversation $conversation, string $model, array $usage): void
    {
        $id = Models::exists($model) ? $model : $this->settings->model();
        AiUsage::create([
            'user_id' => $conversation->user_id,
            'model' => $model,
            'input_tokens' => $usage['input'],
            'output_tokens' => $usage['output'],
            'cache_read_tokens' => $usage['cache_read'],
            'cache_write_tokens' => $usage['cache_write'],
            'cost' => Models::cost($id, $usage['input'], $usage['output'], $usage['cache_read'], $usage['cache_write']),
            'created_at' => now(),
        ]);
    }

    /**
     * JSON turns an empty tool input object into an empty array; the API
     * wants an object back.
     *
     * @param  array{role: string, content: list<array<string, mixed>>}  $message
     * @return array{role: string, content: list<array<string, mixed>>}
     */
    private static function forApi(array $message): array
    {
        foreach ($message['content'] as $i => $block) {
            if (($block['type'] ?? null) === 'tool_use' && ($block['input'] ?? []) === []) {
                $message['content'][$i]['input'] = new \stdClass;
            }
        }

        return $message;
    }
}
