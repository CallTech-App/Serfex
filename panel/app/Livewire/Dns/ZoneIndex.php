<?php

namespace App\Livewire\Dns;

use App\Enums\ZoneStatus;
use App\Jobs\SetupDnsServer;
use App\Livewire\Attributes\ForCustomers;
use App\Models\DnsZone;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Dns\Zones;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('components.layouts.app')]
#[Title('Alan Adları ve DNS · Serfex')]
#[ForCustomers(feature: 'dns', except: ['setupServer', 'openCreate', 'create', 'undoCreate', 'confirmDelete', 'deleteSelected', 'undoDelete'])]
class ZoneIndex extends Component
{
    use WithPagination;

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** "" for all zones, "problem" for the ones that need attention. */
    #[Url(as: 'status', except: '')]
    public string $filter = '';

    /** @var list<int> */
    public array $selected = [];

    public bool $creating = false;

    public string $domain = '';

    /** "template" or "empty". */
    public string $start = 'template';

    public bool $confirmingDelete = false;

    /**
     * What the agent says about PowerDNS: state is one of ready, missing,
     * stopped, installing, failed or offline.
     *
     * @var array{state: string, version?: ?string, error?: ?string}
     */
    public array $server = ['state' => 'ready'];

    public function mount(AgentClient $agent): void
    {
        $this->refreshServer($agent);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Polled while PowerDNS is being installed.
     */
    public function refreshServer(AgentClient $agent): void
    {
        $setup = Setting::get('dns.server', []);
        if (($setup['state'] ?? null) === 'installing') {
            $this->server = ['state' => 'installing'];

            return;
        }
        try {
            $status = $agent->call('dns.status');
        } catch (AgentException $e) {
            $this->server = ['state' => 'offline', 'error' => $e instanceof AgentUnavailableException ? null : $e->reason];

            return;
        }
        $this->server = match (true) {
            (bool) ($status['running'] ?? false) => ['state' => 'ready', 'version' => $status['version'] ?? null],
            ($setup['state'] ?? null) === 'failed' => ['state' => 'failed', 'error' => $setup['error'] ?? null],
            (bool) ($status['configured'] ?? false) => ['state' => 'stopped', 'error' => $status['error'] ?? null],
            default => ['state' => 'missing'],
        };
    }

    /**
     * Install (or repair) PowerDNS in the background.
     */
    public function setupServer(): void
    {
        Setting::put('dns.server', ['state' => 'installing', 'at' => now()->toIso8601String()]);
        $this->server = ['state' => 'installing'];
        SetupDnsServer::dispatch();
    }

    public function openCreate(): void
    {
        $this->reset('domain', 'start');
        $this->resetValidation();
        $this->creating = true;
    }

    public function create(Zones $zones): void
    {
        $this->domain = Hostnames::normalize($this->domain);
        $this->validate([
            'domain' => [
                'required',
                fn ($attr, $value, $fail) => Hostnames::isValidDomain($value) ? null : $fail(__('Geçerli bir alan adı girin, örneğin ornek.com.tr.')),
                Rule::unique('dns_zones', 'name'),
            ],
            'start' => ['required', Rule::in(['template', 'empty'])],
        ], [
            'domain.unique' => __('Bu alan adının DNS bölgesi zaten var.'),
        ], [
            'domain' => __('alan adı'),
        ]);

        $zone = $zones->create($this->domain, empty: $this->start === 'empty');

        $this->creating = false;
        $this->dispatch('toast',
            message: __(':name eklendi.', ['name' => $zone->name]),
            source: $this->getId(),
            undo: ['method' => 'undoCreate', 'args' => [$zone->id]],
        );
    }

    public function undoCreate(int $id, Zones $zones): void
    {
        $zone = DnsZone::find($id);
        if ($zone !== null) {
            $zones->delete($zone);
        }
    }

    public function confirmDelete(): void
    {
        if ($this->selected !== []) {
            $this->confirmingDelete = true;
        }
    }

    public function deleteSelected(Zones $zones): void
    {
        $list = DnsZone::whereKey($this->selected)->get();
        $tokens = $zones->delete(...$list->all());
        $this->selected = [];
        $this->confirmingDelete = false;
        if ($tokens === []) {
            return;
        }
        $this->dispatch('toast',
            message: count($tokens) === 1 ? __(':name siliniyor.', ['name' => $list->firstWhere('id', array_key_first($tokens))->name]) : __(':tokens DNS bölgesi siliniyor.', ['tokens' => count($tokens)]),
            source: $this->getId(),
            undo: ['method' => 'undoDelete', 'args' => [array_values($tokens)]],
        );
    }

    /**
     * @param  list<string>  $tokens
     */
    public function undoDelete(array $tokens, Zones $zones): void
    {
        $restored = array_filter(array_map(fn ($t) => $zones->undo((string) $t), $tokens));
        $this->dispatch('toast', message: count($restored) === count($tokens)
            ? __('Silme geri alındı.')
            : __('Bazı bölgeler geri alınamadı; aynı adla yeni bir bölge eklenmiş olabilir.'));
    }

    public function render(): View
    {
        $zones = DnsZone::visibleTo(auth()->user())
            ->with('site')
            ->withCount('records')
            ->when($this->search !== '', function ($q) {
                $term = '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%';
                $q->where('name', 'like', $term);
            })
            ->when($this->filter === 'problem', fn ($q) => $q->where('status', ZoneStatus::Failed))
            ->orderBy('name')
            ->paginate(25);

        $selectedZones = $this->confirmingDelete ? DnsZone::visibleTo(auth()->user())->whereKey($this->selected)->orderBy('name')->get() : collect();

        return view('livewire.dns.index', [
            'zones' => $zones,
            'busy' => $zones->getCollection()->contains(fn (DnsZone $z) => $z->status->isBusy()),
            'selectedZones' => $selectedZones,
        ]);
    }
}
