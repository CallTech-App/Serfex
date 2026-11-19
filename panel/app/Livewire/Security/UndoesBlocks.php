<?php

namespace App\Livewire\Security;

use App\Services\Agent\AgentException;
use App\Services\Monitor\MonitorException;
use App\Services\Security\BlockUndo;

/**
 * What the IP engelleri pages share: a toast with "Geri al" for every
 * action, the refusal banner, and the address the reader is connected
 * from, which is never blocked.
 */
trait UndoesBlocks
{
    /** Why the last action could not be done, shown above the card. */
    public ?string $refused = null;

    public function undo(string $token, BlockUndo $undo): void
    {
        try {
            $done = $undo->run($token, auth()->user());
        } catch (AgentException|MonitorException $e) {
            $this->refused = __('Geri alınamadı: :for_people', ['for_people' => self::why($e)]);

            return;
        }
        if ($done) {
            $this->dispatch('toast', message: __('Geri alındı.'));
        }
    }

    /**
     * Says what was done, with "Geri al" when there is something to undo.
     *
     * @param  array<string, mixed>  $steps  see BlockUndo
     */
    protected function done(string $message, array $steps = []): void
    {
        $this->refused = null;
        if (array_filter($steps) === []) {
            $this->dispatch('toast', message: $message);

            return;
        }
        $this->dispatch('toast', message: $message, source: $this->getId(),
            undo: ['method' => 'undo', 'args' => [app(BlockUndo::class)->keep($steps, auth()->user())]]);
    }

    /**
     * The address this request came from.
     *
     * @return list<string>
     */
    protected function mine(): array
    {
        return array_values(array_filter([(string) request()->ip()]));
    }

    protected static function why(AgentException|MonitorException $e): string
    {
        return $e instanceof AgentException ? $e->forPeople() : $e->getMessage();
    }

    /**
     * Addresses an action left out, as one sentence for the banner.
     *
     * @param  list<array{text: string, message: string}>  $skipped
     */
    protected static function leftOut(array $skipped): ?string
    {
        if ($skipped === []) {
            return null;
        }
        $shown = array_map(fn (array $s) => $s['text'].' ('.$s['message'].')', array_slice($skipped, 0, 5));
        $more = count($skipped) - count($shown);

        return __('Atlanan adresler: :list', ['list' => implode('; ', $shown)]).($more > 0 ? ' '.__('ve :count adres daha.', ['count' => $more]) : '');
    }
}
