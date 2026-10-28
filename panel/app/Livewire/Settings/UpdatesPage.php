<?php

namespace App\Livewire\Settings;

use App\Models\PanelUpdate;
use App\Services\Updates\Updates;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Güncellemeler (Araçlar ve Ayarlar, administrators only): the running
 * Serfex release, the newer ones with their notes, one-click update with
 * its steps shown live, the way back to the previous release, the
 * history and the channel. While an update runs the panel restarts, so
 * its progress is read with plain requests (UpdateProgressController)
 * that keep trying through the restart.
 */
#[Layout('components.layouts.app')]
#[Title('Güncellemeler · Serfex')]
class UpdatesPage extends Component
{
    #[Url(except: 'history')]
    public string $tab = 'history';

    /** upgrade or rollback, while asking to confirm. */
    public ?string $confirming = null;

    public string $channel = 'stable';

    public ?string $refused = null;

    public function mount(Updates $updates): void
    {
        if (! in_array($this->tab, ['history', 'policy'], true)) {
            $this->tab = 'history';
        }
        $this->channel = $updates->channel();
        $updates->reconcile();
    }

    public function check(Updates $updates): void
    {
        $this->refused = null;
        $result = $updates->check();
        if (is_string($result)) {
            $this->refused = $result;

            return;
        }
        $release = $updates->available($result);
        $this->dispatch('toast', message: $release ? __('Serfex :version hazır.', ['version' => $release['version']]) : __('Serfex güncel.'));
    }

    public function ask(string $kind): void
    {
        if (in_array($kind, ['upgrade', 'rollback'], true)) {
            $this->refused = null;
            $this->confirming = $kind;
        }
    }

    public function confirm(Updates $updates): void
    {
        $kind = $this->confirming;
        $this->confirming = null;
        if ($kind === null) {
            return;
        }
        $result = $kind === 'rollback' ? $updates->rollback(auth()->user()) : $updates->start(auth()->user());
        if (is_string($result)) {
            $this->refused = $result;
        }
    }

    public function saveChannel(Updates $updates): void
    {
        $this->validate(['channel' => ['required', Rule::in(array_keys(Updates::CHANNELS))]]);
        $previous = $updates->channel();
        if ($previous === $this->channel) {
            return;
        }
        $updates->setChannel($this->channel);
        $updates->check();
        $this->dispatch('toast',
            message: __('Kanal: :channels.', ['channels' => Updates::CHANNELS[$this->channel]]),
            source: $this->getId(),
            undo: ['method' => 'undoChannel', 'args' => [$previous]],
        );
    }

    public function undoChannel(string $previous, Updates $updates): void
    {
        if (array_key_exists($previous, Updates::CHANNELS)) {
            $updates->setChannel($previous);
            $this->channel = $previous;
            $updates->check();
        }
    }

    public function render(Updates $updates): View
    {
        $status = $updates->status(true, $error);
        $running = PanelUpdate::running()->latest('id')->first();
        $last = PanelUpdate::whereNotIn('status', PanelUpdate::RUNNING)->latest('id')->first();
        $releases = $updates->available($status) ? (array) $status['releases'] : [];

        return view('livewire.settings.updates', [
            'status' => $status,
            'agentError' => $status === null ? ($error ?: __('Sunucu ajanına ulaşılamıyor.')) : null,
            'releases' => $releases,
            'release' => $releases[0] ?? null,
            'running' => $running,
            // Shown with Undo for a while after it ran.
            'recent' => $last !== null && $running === null && $last->finished_at?->gt(now()->subMinutes(30)) ? $last : null,
            'history' => PanelUpdate::with('user')->latest('id')->limit(20)->get(),
            'blocked' => $updates->refusal($status, $error),
        ]);
    }
}
