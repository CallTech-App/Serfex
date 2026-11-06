<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Files\WebTerminal;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * SSH terminali: the site user's shell in the browser (WebTerminal). The
 * page asks for a ticket when the administrator clicks; the browser's
 * terminal (resources/js/terminal.js) connects with it on its own.
 */
#[Layout('components.layouts.app')]
class SiteTerminal extends Component
{
    public int $siteId;

    public function mount(int $site): void
    {
        abort_unless(auth()->user()->isFullAdmin(), 403);
        $this->siteId = Site::findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::with('systemUser.customer')->findOrFail($this->siteId);
    }

    /**
     * A ticket for a new session, or ['error' => why not].
     *
     * @return array<string, mixed>
     */
    public function start(WebTerminal $terminal): array
    {
        abort_unless(auth()->user()->isFullAdmin(), 403);
        $ticket = $terminal->open(auth()->user(), $this->site(), request()->ip());

        return is_string($ticket) ? ['error' => $ticket] : $ticket;
    }

    /**
     * The page's connection closed: logs the end once and says why.
     */
    public function ended(WebTerminal $terminal, string $id): ?string
    {
        abort_unless(auth()->user()->isFullAdmin(), 403);

        return $terminal->ended($this->site(), $id);
    }

    /**
     * Ends another open session of this account (another tab, another
     * administrator).
     */
    public function endSession(WebTerminal $terminal, string $id): void
    {
        abort_unless(auth()->user()->isFullAdmin(), 403);
        $site = $this->site();
        try {
            $terminal->close($site->systemUser, $id);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: $e->forPeople());

            return;
        }
        $this->dispatch('toast', message: __('Terminal oturumu kapatıldı.'));
    }

    public function render(WebTerminal $terminal): View
    {
        $site = $this->site();
        $refusal = $terminal->refusal(auth()->user(), $site);
        $open = $site->systemUser === null ? [] : array_values(array_filter($terminal->sessions($site->systemUser), fn ($s) => $s['state'] !== 'ended'));

        return view('livewire.sites.terminal', [
            'site' => $site,
            'account' => $site->systemUser,
            'refusal' => $refusal,
            'open' => $open,
            'idle' => WebTerminal::IDLE_MINUTES,
            'max' => WebTerminal::MAX_MINUTES,
        ])->title(__('SSH terminali · :domain · Serfex', ['domain' => $site->domain]));
    }
}
