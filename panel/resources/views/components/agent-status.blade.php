<?php

use App\Services\Agent\AgentClient;
use Livewire\Component;

new class extends Component
{
    public bool $online = false;

    public function mount(AgentClient $agent): void
    {
        $this->refresh($agent);
    }

    public function refresh(AgentClient $agent): void
    {
        $this->online = $agent->ping();
    }
};
?>

<div class="flex flex-1 items-center gap-3">
    @if ($online)
        <span class="size-2.5 rounded-full bg-ok"></span>
        <span>Sunucu ajanı bağlı</span>
    @else
        <span class="size-2.5 rounded-full bg-crit"></span>
        <span>Sunucu ajanına ulaşılamıyor</span>
    @endif
    <button type="button" wire:click="refresh" class="ml-auto h-8 rounded-md border border-line bg-surface px-3 text-[13px] hover:bg-surface-2">
        Yenile
    </button>
</div>
