<?php

namespace App\Livewire\Security;

use App\Models\SecurityFinding;
use App\Services\Agent\AgentException;
use App\Services\Security\Scans;
use Illuminate\Database\Eloquent\Builder;

/**
 * Fixing, ignoring and undoing security scan findings, for the pages that
 * list them. Each page limits the findings to what its user may touch.
 */
trait HandlesFindings
{
    /**
     * The findings this page may act on.
     *
     * @return Builder<SecurityFinding>
     */
    abstract protected function findingScope(): Builder;

    public function fixFinding(int $id, string $action, Scans $scans): void
    {
        $finding = $this->findingScope()->with('site.systemUser')->find($id);
        if ($finding === null || ! array_key_exists($action, SecurityFinding::FIXES)) {
            return;
        }
        try {
            $scans->fix($finding, $action, auth()->user());
        } catch (AgentException $e) {
            $this->dispatch('toast', message: Scans::message($e));

            return;
        }
        $done = SecurityFinding::FIXES[$action][1];
        $this->dispatch('toast',
            message: $action === 'quarantine' ? $done.'; dosya Dosya Yöneticisi\'nin çöp kutusunda '.Scans::QUARANTINE_DAYS.' gün bekler.' : $done.'.',
            source: $this->getId(),
            undo: ['method' => 'undoFinding', 'args' => [$finding->id]],
        );
    }

    public function undoFinding(int $id, Scans $scans): void
    {
        $finding = $this->findingScope()->with('site.systemUser')->find($id);
        if ($finding === null) {
            return;
        }
        try {
            $scans->undo($finding);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: Scans::message($e));

            return;
        }
        $this->dispatch('toast', message: 'Düzeltme geri alındı.');
    }

    public function ignoreFinding(int $id, Scans $scans): void
    {
        $finding = $this->findingScope()->find($id);
        if ($finding === null) {
            return;
        }
        $scans->ignore($finding, auth()->user());
        $this->dispatch('toast', message: 'Bulgu yok sayıldı; sonraki taramalarda da gösterilmez.', source: $this->getId(),
            undo: ['method' => 'reopenFinding', 'args' => [$finding->id]]);
    }

    public function reopenFinding(int $id, Scans $scans): void
    {
        $finding = $this->findingScope()->find($id);
        if ($finding !== null) {
            $scans->reopen($finding);
        }
    }
}
