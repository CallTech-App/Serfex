<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Sites\SiteLogs as Logs;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Günlükler: the end of a site's access and error logs, filtered as you
 * type, followed live when asked, and downloadable whole.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteLogs extends Component
{
    public const COUNTS = [100, 500, 1000];

    public int $siteId;

    #[Url(as: 'tur', except: 'access')]
    public string $kind = 'access';

    public string $filter = '';

    /** Only failed requests (4xx, 5xx) or error lines. */
    public bool $problems = false;

    public int $count = 100;

    /** Follow the log every few seconds. */
    public bool $live = false;

    public function mount(int $site): void
    {
        $this->siteId = Site::visibleTo(auth()->user())->findOrFail($site)->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser')->findOrFail($this->siteId);
    }

    public function switchTab(string $kind): void
    {
        $this->kind = $kind;
    }

    public function refresh(): void
    {
        // Rendering reads the log again.
    }

    public function render(Logs $logs): View
    {
        $site = $this->site();
        $kinds = Logs::kinds($site);
        if (! isset($kinds[$this->kind])) {
            $this->kind = 'access';
        }
        if (! in_array($this->count, self::COUNTS, true)) {
            $this->count = 100;
        }
        $log = null;
        $error = null;
        try {
            $log = $logs->read($site, $this->kind);
        } catch (AgentException $e) {
            $error = 'Günlük okunamadı: '.$e->forPeople();
        }

        return view('livewire.sites.logs', [
            'site' => $site,
            'kinds' => $kinds,
            'log' => $log,
            'error' => $error,
            'lines' => $log ? Logs::lines($log['content'], $log['truncated'], $this->kind, $this->filter, $this->problems, $this->count) : [],
        ])->title('Günlükler · '.$site->domain.' · Servik');
    }
}
