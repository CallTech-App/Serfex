<?php

namespace App\Services\Ai;

use App\Models\AiAction;
use App\Models\AiConversation;
use Illuminate\Support\Str;

/**
 * Turns a conversation's stored API messages into what the chat shows:
 * questions, and answers made of text, the list of what was read, and
 * cards for actions.
 */
final class Transcript
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function items(AiConversation $conversation): array
    {
        $actions = $conversation->actions()->get()->keyBy('tool_use_id');
        $items = [];
        $answer = null;
        foreach ($conversation->messages()->get() as $m) {
            if ($m->kind === 'user') {
                if ($answer !== null) {
                    $items[] = $answer;
                    $answer = null;
                }
                $items[] = ['type' => 'user', 'text' => (string) $m->text, 'at' => $m->created_at];

                continue;
            }
            if ($m->kind !== 'assistant') {
                continue;
            }
            $answer ??= ['type' => 'answer', 'parts' => [], 'at' => $m->created_at, 'stop' => null];
            foreach ($m->content as $block) {
                $type = $block['type'] ?? null;
                if ($type === 'text' && trim((string) $block['text']) !== '') {
                    $answer['parts'][] = ['type' => 'text', 'html' => self::markdown((string) $block['text'])];
                } elseif ($type === 'tool_use' && isset(Tools::READS[$block['name']])) {
                    $label = Tools::STEP_LABELS[$block['name']].(is_string($block['input']['domain'] ?? null) ? ' · '.$block['input']['domain'] : '');
                    $last = array_key_last($answer['parts']);
                    if ($last !== null && $answer['parts'][$last]['type'] === 'steps') {
                        $answer['parts'][$last]['steps'][] = $label;
                    } else {
                        $answer['parts'][] = ['type' => 'steps', 'steps' => [$label]];
                    }
                } elseif ($type === 'tool_use' && ($action = $actions[$block['id']] ?? null) instanceof AiAction) {
                    $answer['parts'][] = ['type' => 'action', 'action' => $action];
                }
            }
            $answer['stop'] = $m->stop_reason;
        }
        if ($answer !== null) {
            $items[] = $answer;
        }

        return $items;
    }

    /**
     * Claude's Markdown as HTML. Raw HTML is dropped and unsafe links are
     * not followed, so an answer cannot inject markup into the panel.
     */
    public static function markdown(string $text): string
    {
        return Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'max_nesting_level' => 20]);
    }
}
