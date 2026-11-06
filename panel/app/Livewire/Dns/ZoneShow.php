<?php

namespace App\Livewire\Dns;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Certificate;
use App\Models\CloudflareAccount;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\Site;
use App\Services\Cloudflare\CloudflareApi;
use App\Services\Cloudflare\CloudflareException;
use App\Services\Cloudflare\CloudflareZones;
use App\Services\Dns\Zones;
use App\Support\DnsRecords;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'dns')]
class ZoneShow extends Component
{
    use EditsRecords;

    /** Order of record types in the table: delegation first, like a zone file. */
    private const TYPE_ORDER = ['NS', 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    public int $zoneId;

    /** Set when the page is shown inside a site (Siteler → site → Alan adları ve DNS). */
    #[Locked]
    public ?int $siteId = null;

    #[Url(except: 'records')]
    public string $tab = 'records';

    #[Url(as: 'type', except: '')]
    public string $type = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    public bool $confirmingReset = false;

    // Settings tab
    public int $defaultTtl = 3600;

    public string $hostmaster = '';

    public int $refresh = 10800;

    public int $retry = 3600;

    public int $expire = 604800;

    public int $minimum = 3600;

    public bool $dnssec = false;

    /** Turning DNSSEC off waits for this confirmation. */
    public bool $confirmingDnssecOff = false;

    /** Through Cloudflare's proxy, for the record being edited. */
    public bool $recordProxied = false;

    /** "DNS'i Cloudflare'e bağla" is open. */
    public bool $connecting = false;

    /**
     * The zone found at Cloudflare and the comparison with its records.
     *
     * @var array{account_id: int, account: string, zone: array<string, mixed>, same: int, rows: list<array<string, mixed>>}|null
     */
    public ?array $connectPlan = null;

    /** Row key => choice, for the comparison. @var array<string, string> */
    public array $connectChoices = [];

    public ?string $connectError = null;

    /** "Bu sunucuya geri al" waits for this confirmation. */
    public bool $confirmingDetach = false;

    /**
     * Takes the id rather than a bound model, like SiteShow: polling a zone
     * that was just deleted must not turn into a 404.
     */
    public function mount(int $zone): void
    {
        $this->zoneId = DnsZone::visibleTo(auth()->user())->findOrFail($zone)->id;
        $this->fillSettings();
    }

    /**
     * Polled while the agent works on the zone.
     */
    public function refreshStatus(): void
    {
        if ($this->zone() === null) {
            session()->flash('toast', __('DNS bölgesi silindi.'));
            $this->siteId !== null
                ? $this->redirectRoute('sites.dns', ['site' => $this->siteId], navigate: true)
                : $this->redirectRoute('dns.index', navigate: true);
        }
    }

    public function retry(): void
    {
        $zone = $this->zone();
        if ($zone === null || $zone->status !== ZoneStatus::Failed) {
            return;
        }
        $zone->update(['status' => ZoneStatus::Syncing, 'last_error' => null]);
        SyncDnsZone::dispatch($zone);
    }

    public function openAdd(): void
    {
        $zone = $this->zone();
        if ($zone !== null) {
            $this->openRecordForm(defaultTtl: $zone->default_ttl);
            $this->recordProxied = false;
        }
    }

    public function openEdit(int $id): void
    {
        $record = $this->zone()?->records()->find($id);
        if ($record !== null) {
            $this->openRecordForm($record->id, $record->toRecord());
            $this->recordProxied = (bool) $record->proxied;
        }
    }

    public function saveRecord(Zones $zones): void
    {
        $zone = $this->zone();
        if ($zone === null || $zone->status === ZoneStatus::Deleting) {
            return;
        }
        if ($this->recordKey !== null) {
            // The type of an existing record does not change.
            $this->recordType = $zone->records()->findOrFail($this->recordKey)->type;
        }
        $record = $this->validatedRecord($zone->name);
        if ($record === null) {
            return;
        }
        $others = $zone->records()->when($this->recordKey !== null, fn ($q) => $q->whereKeyNot($this->recordKey))->get();
        if ($this->conflictsWith($record, $others->map(fn (DnsRecord $r) => $r->toRecord()))) {
            return;
        }
        if (! $zone->atCloudflare() && $this->recordKey !== null && $record['name'] !== $this->originalName() && $this->isLastApexNs()) {
            $this->addError('recordName', __('Alan adının en az bir NS kaydı kalmalı.'));

            return;
        }

        if ($zone->atCloudflare() && in_array($record['type'], DnsRecord::PROXIABLE, true)) {
            $record['proxied'] = $this->recordProxied;
        }
        $siblings = $others->where('name', $record['name'])->where('type', $record['type'])->where('ttl', '!=', $record['ttl']);
        $key = $this->recordKey;
        $token = $zones->change($zone, function (DnsZone $zone) use ($record, $key, $siblings) {
            if ($key === null) {
                $zone->records()->create($record);
            } else {
                $zone->records()->whereKey($key)->update($record);
            }
            // PowerDNS keeps one TTL per name and type.
            $zone->records()->whereKey($siblings->modelKeys())->update(['ttl' => $record['ttl']]);
        });

        $this->editingRecord = false;
        $name = DnsRecords::fqdn($record['name'], $zone->name);
        $message = $key === null
            ? __(':type kaydı eklendi: :name', ['type' => $record['type'], 'name' => $name])
            : __(':type kaydı güncellendi: :name', ['type' => $record['type'], 'name' => $name]);
        if ($siblings->isNotEmpty()) {
            $message .= __('. Aynı ad ve türdeki :count kaydın TTL\'si de değişti.', ['count' => $siblings->count()]);
        }
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function deleteSelected(Zones $zones): void
    {
        $zone = $this->zone();
        if ($zone === null || $this->selected === []) {
            return;
        }
        $apexNs = $zone->records()->where('name', '@')->where('type', 'NS');
        if (! $zone->atCloudflare() && (clone $apexNs)->whereKeyNot($this->selected)->doesntExist()) {
            $this->dispatch('toast', message: __('Alan adının en az bir NS kaydı kalmalı; NS kayıtlarının hepsi silinemez.'));

            return;
        }
        $ids = $zone->records()->whereKey($this->selected)->pluck('id')->all();
        $token = $zones->change($zone, fn (DnsZone $zone) => $zone->records()->whereKey($ids)->delete());
        $this->selected = [];
        $this->dispatch('toast', message: __(':ids kayıt silindi.', ['ids' => count($ids)]), source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function resetToTemplate(Zones $zones): void
    {
        $zone = $this->zone();
        if ($zone === null) {
            return;
        }
        $token = $zones->reset($zone);
        $this->confirmingReset = false;
        $this->selected = [];
        $this->dispatch('toast', message: __('DNS bölgesi şablon kayıtlarına döndü.'), source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function saveSettings(Zones $zones, bool $confirmed = false): void
    {
        $zone = $this->zone();
        if ($zone === null) {
            return;
        }
        $this->hostmaster = trim($this->hostmaster);
        $this->validate([
            'defaultTtl' => ['required', 'integer', 'min:'.DnsRecords::MIN_TTL, 'max:'.DnsRecords::MAX_TTL],
            'hostmaster' => ['nullable', 'email:rfc'],
            'refresh' => ['required', 'integer', 'min:1200', 'max:2419200'],
            'retry' => ['required', 'integer', 'min:180', 'max:2419200'],
            'expire' => ['required', 'integer', 'min:3600', 'max:4838400'],
            'minimum' => ['required', 'integer', 'min:60', 'max:86400'],
        ], [], [
            'defaultTtl' => __('varsayılan TTL'),
            'hostmaster' => __('yönetici e-postası'),
            'refresh' => 'yenileme',
            'retry' => __('yeniden deneme'),
            'expire' => __('sona erme'),
            'minimum' => __('negatif TTL'),
        ]);
        // Resolvers that check signatures stop finding a domain whose
        // registrar still lists a DS record for a key that is gone.
        if ($zone->dnssec && ! $this->dnssec && ! $confirmed) {
            $this->confirmingDnssecOff = true;

            return;
        }
        $this->confirmingDnssecOff = false;

        $signing = $this->dnssec !== $zone->dnssec;
        $token = $zones->change($zone, fn (DnsZone $zone) => $zone->update([
            'default_ttl' => $this->defaultTtl,
            'hostmaster' => $this->hostmaster === '' || $this->hostmaster === 'hostmaster@'.$zone->name ? null : $this->hostmaster,
            'refresh' => $this->refresh,
            'retry' => $this->retry,
            'expire' => $this->expire,
            'minimum' => $this->minimum,
            'dnssec' => $this->dnssec,
        ]));
        $message = match (true) {
            $signing && $this->dnssec => __('DNSSEC açılıyor. DS kaydı birazdan aşağıda görünecek; alan adı kayıt firmanıza ekleyin.'),
            $signing => __('DNSSEC kapatıldı. Bölge artık imzasız yayınlanıyor.'),
            default => __('DNS ayarları kaydedildi.'),
        };
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function confirmDnssecOff(Zones $zones): void
    {
        $this->saveSettings($zones, true);
    }

    public function keepDnssec(): void
    {
        $this->confirmingDnssecOff = false;
        $this->dnssec = true;
    }

    /**
     * Turn Cloudflare's proxy on or off for a record.
     */
    public function toggleProxy(int $id, Zones $zones): void
    {
        $zone = $this->zone();
        $record = $zone?->records->find($id);
        if ($zone === null || ! $zone->atCloudflare() || $record === null || ! $record->canBeProxied()) {
            return;
        }
        $on = ! $record->proxied;
        $token = $zones->change($zone, fn (DnsZone $zone) => $zone->records()->whereKey($id)->update(['proxied' => $on]));
        $name = DnsRecords::fqdn($record->name, $zone->name);
        $this->dispatch('toast', message: $on ? __(':name artık Cloudflare vekili üzerinden.', ['name' => $name]) : __(':name artık yalnızca DNS.', ['name' => $name]),
            source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    /**
     * Ask Cloudflare to look at the name servers again and sync now.
     */
    public function syncNow(): void
    {
        $zone = $this->zone();
        if ($zone === null || ! $zone->atCloudflare() || $zone->status === ZoneStatus::Deleting) {
            return;
        }
        if ($zone->cloudflareStatus() === 'pending') {
            try {
                CloudflareApi::for($zone->cloudflareAccount->token)->activationCheck((string) $zone->cloudflare_zone_id);
            } catch (CloudflareException) {
                // Cloudflare allows it only now and then; the sync still runs.
            }
        }
        $zone->update(['status' => ZoneStatus::Syncing, 'last_error' => null]);
        SyncDnsZone::dispatch($zone);
    }

    public function strictSsl(CloudflareZones $cloudflare): void
    {
        $this->setSslMode('strict', $cloudflare);
    }

    public function setSslMode(string $mode, CloudflareZones $cloudflare): void
    {
        $zone = $this->zone();
        if ($zone === null || ! $zone->atCloudflare() || ! auth()->user()->is_admin || ! in_array($mode, ['off', 'flexible', 'full', 'strict'], true)) {
            return;
        }
        try {
            $cloudflare->setSsl($zone, $mode);
        } catch (CloudflareException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: __('Cloudflare SSL modu: :mode.', ['mode' => self::sslLabel($mode)]));
    }

    public function setCloudflareDnssec(bool $on, CloudflareZones $cloudflare): void
    {
        $zone = $this->zone();
        if ($zone === null || ! $zone->atCloudflare() || ! auth()->user()->is_admin) {
            return;
        }
        try {
            $cloudflare->setDnssec($zone, $on);
        } catch (CloudflareException $e) {
            $this->dispatch('toast', message: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: $on ? __('Cloudflare DNSSEC\'i açıyor. DS kaydı birazdan aşağıda görünecek; kayıt firmanıza ekleyin.') : __('Cloudflare DNSSEC\'i kapatıyor.'));
    }

    /**
     * Look for the zone in the connected accounts and compare its records
     * there with the panel's.
     */
    public function openConnect(CloudflareZones $cloudflare): void
    {
        $zone = $this->zone();
        if ($zone === null || $zone->atCloudflare() || ! auth()->user()->is_admin) {
            return;
        }
        $this->reset('connectPlan', 'connectChoices', 'connectError');
        $this->connecting = true;
        $found = $cloudflare->find($zone->name);
        if ($found === null || ($found['zone']['name'] ?? null) !== $zone->name) {
            $this->connectError = CloudflareAccount::where('status', CloudflareAccount::OK)->exists()
                ? __(':zone bağlı Cloudflare hesaplarında yok. Önce Cloudflare\'de ekleyin ya da alan adını gören bir hesap bağlayın.', ['zone' => $zone->name])
                : __('Bağlı Cloudflare hesabı yok. Önce Alan Adları ve DNS → Cloudflare sayfasından bir hesap bağlayın.');

            return;
        }
        try {
            $remote = CloudflareApi::for($found['account']->token)->records((string) $found['zone']['id']);
        } catch (CloudflareException $e) {
            $this->connectError = $e->getMessage();

            return;
        }
        $plan = $cloudflare->compare($zone->name, $zone->default_ttl, $zone->records->map(fn (DnsRecord $r) => $r->toRecord())->all(), $remote);
        $this->connectPlan = [
            'account_id' => $found['account']->id,
            'account' => $found['account']->name,
            'zone' => $found['zone'],
            ...$plan,
        ];
        $this->connectChoices = array_column($plan['rows'], 'choice', 'key');
    }

    public function connectCloudflare(CloudflareZones $cloudflare): void
    {
        $zone = $this->zone();
        $account = $this->connectPlan !== null ? CloudflareAccount::find($this->connectPlan['account_id']) : null;
        if ($zone === null || $zone->atCloudflare() || $account === null || ! auth()->user()->is_admin) {
            return;
        }
        try {
            $cloudflare->attach($zone, $account, $this->connectPlan['zone'], $this->connectChoices);
        } catch (CloudflareException $e) {
            $this->connectError = $e->getMessage();

            return;
        }
        $this->connecting = false;
        $this->connectPlan = null;
        $this->dispatch('toast', message: __(':zone DNS\'i Cloudflare\'e bağlandı; kayıtlar eşitleniyor.', ['zone' => $zone->name]),
            source: $this->getId(), undo: ['method' => 'detachCloudflare', 'args' => []]);
    }

    /**
     * Serve the zone from this server again.
     */
    public function detachCloudflare(CloudflareZones $cloudflare): void
    {
        $zone = $this->zone();
        if ($zone === null || ! $zone->atCloudflare() || ! auth()->user()->is_admin) {
            return;
        }
        $cloudflare->detach($zone);
        $this->confirmingDetach = false;
        $this->fillSettings();
        $this->dispatch('toast', message: __(':zone yeniden bu sunucunun DNS\'inde. Kayıt firmasında ad sunucularını bu sunucununkiler yapın; Cloudflare\'deki kayıtlara dokunulmadı.', ['zone' => $zone->name]));
    }

    public static function sslLabel(?string $mode): string
    {
        return match ($mode) {
            'strict' => __('Full (strict)'),
            'full' => __('Full'),
            'flexible' => __('Esnek (Flexible)'),
            'off' => __('Kapalı'),
            default => '—',
        };
    }

    public function undo(string $token, Zones $zones): void
    {
        if ($zones->undo($token) === null) {
            $this->dispatch('toast', message: __('Bu değişiklik artık geri alınamıyor.'));

            return;
        }
        $this->fillSettings();
        $this->dispatch('toast', message: __('Değişiklik geri alındı.'));
    }

    public function export(): StreamedResponse
    {
        $zone = $this->zone() ?? abort(404);

        return response()->streamDownload(function () use ($zone) {
            echo $zone->zoneFile();
        }, $zone->name.'.zone', ['Content-Type' => 'text/dns']);
    }

    public function render(): View
    {
        $zone = $this->zone();
        if ($zone === null) {
            // Deleted meanwhile; refreshStatus() sends the browser back to the list.
            return view('livewire.sites.gone');
        }

        $term = mb_strtolower(trim($this->search));
        $records = $zone->records
            ->when($this->type !== '', fn ($c) => $c->where('type', $this->type))
            ->when($term !== '', fn ($c) => $c->filter(fn (DnsRecord $r) => str_contains(mb_strtolower(DnsRecords::fqdn($r->name, $zone->name).' '.$r->content), $term)))
            ->sortBy(fn (DnsRecord $r) => [$r->name === '@' ? '' : $r->name, array_search($r->type, self::TYPE_ORDER), $r->priority ?? 0, $r->id]);

        return view('livewire.dns.show', [
            'zone' => $zone,
            'records' => $records,
            'canConnect' => ! $zone->atCloudflare() && auth()->user()->is_admin && CloudflareAccount::where('status', CloudflareAccount::OK)->exists(),
            'certificate' => $zone->atCloudflare() ? $this->certificateOf($zone) : null,
        ])->title($zone->name.' · DNS · Serfex');
    }

    private function zone(): ?DnsZone
    {
        return DnsZone::visibleTo(auth()->user())->with(['records', 'site', 'cloudflareAccount'])->find($this->zoneId);
    }

    /**
     * The certificate of the zone's site, for the Cloudflare summary.
     */
    private function certificateOf(DnsZone $zone): ?Certificate
    {
        $site = $zone->site ?? Site::where('domain', $zone->name)->orWhere('domain', 'like', '%.'.$zone->name)->orderByRaw('length(domain)')->first();

        return $site?->certificate;
    }

    private function fillSettings(): void
    {
        $zone = $this->zone();
        if ($zone === null) {
            return;
        }
        $this->defaultTtl = $zone->default_ttl;
        $this->hostmaster = $zone->hostmasterEmail();
        $this->refresh = $zone->refresh;
        $this->retry = $zone->retry;
        $this->expire = $zone->expire;
        $this->minimum = $zone->minimum;
        $this->dnssec = $zone->dnssec;
    }

    private function originalName(): ?string
    {
        return $this->zone()?->records->find($this->recordKey)?->name;
    }

    /**
     * True when the record being edited is the zone's only apex NS record.
     */
    private function isLastApexNs(): bool
    {
        $record = $this->zone()?->records->find($this->recordKey);

        return $record !== null && $record->name === '@' && $record->type === 'NS'
            && DnsRecord::where('dns_zone_id', $record->dns_zone_id)->where('name', '@')->where('type', 'NS')->count() === 1;
    }
}
