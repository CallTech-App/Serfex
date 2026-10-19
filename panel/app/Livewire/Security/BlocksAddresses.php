<?php

namespace App\Livewire\Security;

use App\Models\IpBlock;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;

/**
 * "IP'yi engelle" from the WAF's lists: blocks the whole server to an
 * address (the same blocks as Canlı İzleme), with Undo.
 */
trait BlocksAddresses
{
    public bool $blocking = false;

    /** @var array{address: string, minutes: int, reason: string, site: ?int} */
    public array $block = ['address' => '', 'minutes' => 1440, 'reason' => '', 'site' => null];

    public function openBlock(string $address, ?int $site = null, string $reason = ''): void
    {
        $this->block = ['address' => $address, 'minutes' => 1440, 'reason' => mb_substr($reason, 0, 200), 'site' => $site];
        $this->resetValidation();
        $this->blocking = true;
    }

    public function saveBlock(IpBlocks $blocks): void
    {
        $this->validate([
            'block.address' => ['required', 'string', 'max:64'],
            'block.minutes' => ['required', 'integer', 'in:'.implode(',', array_keys(IpBlocks::DURATIONS))],
            'block.reason' => ['nullable', 'string', 'max:200'],
        ], [], ['block.address' => 'adres', 'block.reason' => 'neden']);
        $site = $this->block['site'] ? Site::find($this->block['site']) : null;
        try {
            $b = $blocks->block((string) $this->block['address'], (int) $this->block['minutes'], trim((string) $this->block['reason']), $site,
                'waf', auth()->user(), protect: [(string) request()->ip()]);
        } catch (MonitorException $e) {
            $this->addError('block.address', $e->getMessage());

            return;
        } catch (AgentException $e) {
            $this->addError('block.address', $e instanceof AgentUnavailableException ? 'Sunucu ajanına ulaşılamıyor.' : 'Adres engellenemedi: '.$e->reason);

            return;
        }
        $this->blocking = false;
        $this->dispatch('toast',
            message: "{$b->address} ".($b->expires_at ? IpBlocks::DURATIONS[(int) $this->block['minutes']] : 'kalıcı olarak').' engellendi.',
            source: $this->getId(),
            undo: ['method' => 'unblock', 'args' => [$b->id]],
        );
    }

    public function unblock(int $id, IpBlocks $blocks): void
    {
        $b = IpBlock::find($id);
        if ($b === null) {
            return;
        }
        try {
            $blocks->unblock($b);
        } catch (AgentException $e) {
            $this->refused = 'Engel kaldırılamadı: '.$e->reason;
        }
    }
}
