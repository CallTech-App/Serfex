<?php

namespace App\Console\Commands;

use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Components\Components;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Runs from the scheduler: refreshes the package lists so Yazılım
 * Yönetimi shows which components have updates. Nothing is installed.
 */
#[Signature('servik:components-check')]
#[Description('Check installed software for available updates')]
class CheckComponentUpdates extends Command
{
    public function handle(AgentClient $agent, Components $components): int
    {
        try {
            $agent->call('component.check', [], 300);
        } catch (AgentException $e) {
            $this->warn('Agent: '.$e->getMessage());

            return self::FAILURE;
        }
        $updates = collect($components->statuses() ?? [])->filter(fn ($c) => ($c['latest'] ?? null) !== null)->keys();
        $this->info($updates->isEmpty() ? __('Güncelleme yok.') : __('Güncelleme var: :implode', ['implode' => $updates->implode(', ')]));

        return self::SUCCESS;
    }
}
