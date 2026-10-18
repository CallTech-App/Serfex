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

<div class="flex items-center gap-3">
    @if ($online)
        <span class="size-2.5 rounded-full bg-green-600"></span>
        <span>Sunucu ajanı bağlı</span>
    @else
        <span class="size-2.5 rounded-full bg-red-600"></span>
        <span>Sunucu ajanına ulaşılamıyor</span>
    @endif
    <button type="button" wire:click="refresh" class="ml-auto rounded-md border border-gray-300 px-3 py-1 text-sm hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-800">
        Yenile
    </button>
</div>
