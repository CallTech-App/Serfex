<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Database;
use App\Models\Site;
use App\Models\SiteCopy;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\Backups;
use App\Services\Dns\DnsSettings;
use App\Services\Sites\SiteCopies;
use App\Services\Sites\SiteDomains;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Component;

/**
 * Siteyi kopyala. On a live site: make a staging copy (address, databases
 * too, hidden from search engines) and see its copies. On a copy: bring it
 * up to date from the live site, publish it onto the live site (backed up
 * first, with Undo), hide it from search engines or not.
 */
#[Layout('components.layouts.app')]
#[ForCustomers]
class SiteCopyTool extends Component
{
    public int $siteId;

    public string $domain = '';

    public bool $withDatabases = true;

    public bool $noindex = true;

    /** refresh or publish, while its confirmation is open. */
    public ?string $confirming = null;

    /** @var list<string> */
    public array $parts = SiteCopy::PARTS;

    /** The run whose log is shown. */
    public ?int $shownId = null;

    public function mount(int $site, SiteCopies $copies): void
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $site->id;
        $this->domain = $copies->suggestedDomain($site);
        $this->noindex = $site->copy_of_id !== null ? $site->noindex : true;
        $this->shownId = $this->runs($site)->first()?->id;
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'copyOf.systemUser', 'wordpress')->findOrFail($this->siteId);
    }

    /**
     * Makes a staging copy of this site.
     */
    public function create(SiteCopies $copies): void
    {
        $this->resetValidation();
        $site = $this->site();
        $this->domain = Hostnames::normalize($this->domain);
        if ($refusal = $copies->createRefusal(auth()->user(), $site)) {
            $this->addError('domain', $refusal);

            return;
        }
        if ($refusal = $copies->domainRefusal($site, $this->domain, auth()->user())) {
            $this->addError('domain', $refusal);

            return;
        }
        $run = $copies->create($site, $this->domain, $this->withDatabases, $this->noindex, auth()->user());
        $this->shownId = $run->id;
        $this->dispatch('toast', message: __(':domain hazırlanıyor; dosyalar kopyalanınca açılır.', ['domain' => $run->domain]));
    }

    public function openRun(string $kind): void
    {
        if (in_array($kind, [SiteCopy::REFRESH, SiteCopy::PUBLISH], true)) {
            $this->resetValidation();
            $this->parts = SiteCopy::PARTS;
            $this->confirming = $kind;
        }
    }

    /**
     * Refreshes the copy from its live site, or publishes it, as confirmed.
     */
    public function start(SiteCopies $copies): void
    {
        $site = $this->site();
        $kind = $this->confirming;
        if ($site->copy_of_id === null || ! in_array($kind, [SiteCopy::REFRESH, SiteCopy::PUBLISH], true)) {
            return;
        }
        $parts = array_values(array_intersect(SiteCopy::PARTS, $this->parts));
        if ($parts === []) {
            $this->addError('parts', __('Kopyalanacak en az bir şey seçin.'));

            return;
        }
        if ($refusal = $copies->runRefusal($site, $kind)) {
            $this->addError('parts', $refusal);

            return;
        }
        $run = $copies->start($site, $kind, $parts, auth()->user());
        $this->confirming = null;
        $this->shownId = $run->id;
    }

    /**
     * Undoes a publish from the live site's backup.
     */
    public function undo(SiteCopies $copies, int $id): void
    {
        $run = SiteCopy::where('source_id', $this->site()->copy_of_id)->where('site_id', $this->siteId)->find($id);
        if ($run === null) {
            return;
        }
        $result = $copies->undo($run, auth()->user());
        $this->dispatch('toast', message: is_string($result) ? $result : __('Canlı site aktarmadan önceki yedeğinden geri yükleniyor.'));
    }

    /**
     * Turns the copy's noindex header (and WordPress's setting at the next
     * refresh) on or off.
     */
    public function saveNoindex(SiteDomains $domains): void
    {
        $site = $this->site();
        if ($site->copy_of_id === null || $site->noindex === $this->noindex) {
            return;
        }
        $before = $site->noindex;
        try {
            $domains->change($site, fn () => $site->update(['noindex' => $this->noindex]));
        } catch (AgentException $e) {
            $this->noindex = $before;
            $this->dispatch('toast', message: $e->forPeople());

            return;
        }
        $this->dispatch('toast',
            message: $this->noindex ? __('Arama motorlarından gizleniyor.') : __('Arama motorları artık bu kopyayı dizine ekleyebilir.'),
            source: $this->getId(),
            undo: ['method' => 'undoNoindex', 'args' => [$before]],
        );
    }

    public function undoNoindex(SiteDomains $domains, bool $value): void
    {
        $this->noindex = $value;
        $this->saveNoindex($domains);
    }

    public function show(int $id): void
    {
        $this->shownId = $this->visibleRuns()->whereKey($id)->value('id');
    }

    /**
     * The log of a copy shown on the page, from $offset on.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $op = $this->visibleRuns()->where('run', $run)->first();
        if ($op === null) {
            return null;
        }
        try {
            $chunk = app(AgentClient::class)->call('backup.run.read', ['run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => ! $op->isBusy(), 'error' => __('Sunucu ajanına ulaşılamıyor.')];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $op->isBusy(),
        ];
    }

    /**
     * Runs this page shows: of the site's copies, or of this copy.
     */
    private function visibleRuns()
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($this->siteId);

        return $site->copy_of_id === null
            ? SiteCopy::where('source_id', $site->id)
            : SiteCopy::where('site_id', $site->id);
    }

    /**
     * @return Collection<int, SiteCopy>
     */
    private function runs(Site $site)
    {
        return $this->visibleRuns()->with('site', 'user', 'restore')->latest('id')->limit(10)->get();
    }

    public function render(SiteCopies $copies, DnsSettings $dns): View
    {
        $site = $this->site();
        $runs = $this->runs($site);
        $source = $site->copyOf;

        return view('livewire.sites.copy', [
            'site' => $site,
            'source' => $source,
            'isCopy' => $site->copy_of_id !== null,
            'copies' => $site->copies()->with('certificate')->orderBy('domain')->get(),
            'databases' => Database::where('site_id', $site->id)->orderBy('name')->get(),
            'runs' => $runs,
            'shown' => $this->shownId ? $runs->firstWhere('id', $this->shownId) : null,
            'busy' => $runs->contains(fn (SiteCopy $r) => $r->isBusy() || $r->restore?->isRunning()),
            'createRefusal' => $site->copy_of_id === null ? $copies->createRefusal(auth()->user(), $site) : null,
            'refreshRefusal' => $source ? $copies->runRefusal($site, SiteCopy::REFRESH) : null,
            'publishRefusal' => $source ? $copies->runRefusal($site, SiteCopy::PUBLISH) : null,
            'undoable' => fn (SiteCopy $r) => $r->kind === SiteCopy::PUBLISH && $r->status === 'succeeded' && $copies->undoable($r),
            'safetyDays' => Backups::SAFETY_DAYS,
            'ip' => $dns->ipv4(),
        ])->title(__('Siteyi kopyala · :domain · Serfex', ['domain' => $site->domain]));
    }
}
