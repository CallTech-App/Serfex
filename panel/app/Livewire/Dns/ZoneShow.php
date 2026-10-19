<?php

namespace App\Livewire\Dns;

use App\Enums\ZoneStatus;
use App\Jobs\SyncDnsZone;
use App\Livewire\Attributes\ForCustomers;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\Dns\Zones;
use App\Support\DnsRecords;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
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

    #[Url(except: 'kayitlar')]
    public string $tab = 'kayitlar';

    #[Url(as: 'tur', except: '')]
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
            session()->flash('toast', 'DNS bölgesi silindi.');
            $this->redirectRoute('dns.index', navigate: true);
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
        }
    }

    public function openEdit(int $id): void
    {
        $record = $this->zone()?->records()->find($id);
        if ($record !== null) {
            $this->openRecordForm($record->id, $record->toRecord());
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
        if ($this->recordKey !== null && $record['name'] !== $this->originalName() && $this->isLastApexNs()) {
            $this->addError('recordName', 'Alan adının en az bir NS kaydı kalmalı.');

            return;
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
        $message = "{$record['type']} kaydı ".($key === null ? 'eklendi' : 'güncellendi').": {$name}";
        if ($siblings->isNotEmpty()) {
            $message .= '. Aynı ad ve türdeki '.$siblings->count().' kaydın TTL\'si de değişti.';
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
        if ((clone $apexNs)->whereKeyNot($this->selected)->doesntExist()) {
            $this->dispatch('toast', message: 'Alan adının en az bir NS kaydı kalmalı; NS kayıtlarının hepsi silinemez.');

            return;
        }
        $ids = $zone->records()->whereKey($this->selected)->pluck('id')->all();
        $token = $zones->change($zone, fn (DnsZone $zone) => $zone->records()->whereKey($ids)->delete());
        $this->selected = [];
        $this->dispatch('toast', message: count($ids).' kayıt silindi.', source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
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
        $this->dispatch('toast', message: 'DNS bölgesi şablon kayıtlarına döndü.', source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function saveSettings(Zones $zones): void
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
            'defaultTtl' => 'varsayılan TTL',
            'hostmaster' => 'yönetici e-postası',
            'refresh' => 'yenileme',
            'retry' => 'yeniden deneme',
            'expire' => 'sona erme',
            'minimum' => 'negatif TTL',
        ]);

        $token = $zones->change($zone, fn (DnsZone $zone) => $zone->update([
            'default_ttl' => $this->defaultTtl,
            'hostmaster' => $this->hostmaster === '' || $this->hostmaster === 'hostmaster@'.$zone->name ? null : $this->hostmaster,
            'refresh' => $this->refresh,
            'retry' => $this->retry,
            'expire' => $this->expire,
            'minimum' => $this->minimum,
        ]));
        $this->dispatch('toast', message: 'DNS ayarları kaydedildi.', source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function undo(string $token, Zones $zones): void
    {
        if ($zones->undo($token) === null) {
            $this->dispatch('toast', message: 'Bu değişiklik artık geri alınamıyor.');

            return;
        }
        $this->fillSettings();
        $this->dispatch('toast', message: 'Değişiklik geri alındı.');
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
        ])->title($zone->name.' · DNS · Servik');
    }

    private function zone(): ?DnsZone
    {
        return DnsZone::visibleTo(auth()->user())->with(['records', 'site'])->find($this->zoneId);
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
