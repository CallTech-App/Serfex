<?php

namespace App\Livewire\Dns;

use App\Models\DnsZone;
use App\Models\Setting;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Dns\DnsSettings;
use App\Services\Dns\Zones;
use App\Services\Dns\ZoneTemplate;
use App\Support\DnsRecords;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Server-wide DNS settings: name servers, addresses and SOA defaults for
 * new zones, and the records every new zone starts with.
 */
#[Layout('components.layouts.app')]
#[Title('Sunucu DNS ayarları · Servik')]
class DnsSettingsPage extends Component
{
    use EditsRecords;

    /** Values that stand in for the placeholders while a row is validated. */
    private const SAMPLE = ['<domain>' => 'servik-template.invalid', '<ip4>' => '192.0.2.254', '<ip6>' => '2001:db8::fe'];

    public string $nameservers = '';

    public bool $applyToZones = false;

    public string $ipv4 = '';

    public string $ipv6 = '';

    public int $defaultTtl = 3600;

    public string $hostmaster = '';

    public int $refresh = 10800;

    public int $retry = 3600;

    public int $expire = 604800;

    public int $minimum = 3600;

    /** @var array{ipv4: list<string>, ipv6: list<string>} */
    public array $detected = ['ipv4' => [], 'ipv6' => []];

    public function mount(DnsSettings $settings, ZoneTemplate $template, AgentClient $agent): void
    {
        $template->detectAddresses();
        $this->nameservers = implode(', ', $settings->nameservers());
        $this->ipv4 = (string) $settings->ipv4();
        $this->ipv6 = (string) $settings->ipv6();
        $this->defaultTtl = $settings->defaultTtl();
        $this->hostmaster = (string) $settings->hostmaster();
        ['refresh' => $this->refresh, 'retry' => $this->retry, 'expire' => $this->expire, 'minimum' => $this->minimum] = $settings->soa();
        try {
            $found = $agent->call('system.addresses');
            $this->detected = ['ipv4' => $found['ipv4'] ?? [], 'ipv6' => $found['ipv6'] ?? []];
        } catch (AgentException) {
            // Suggestions only; the fields still work.
        }
    }

    public function save(DnsSettings $settings, ZoneTemplate $template, Zones $zones): void
    {
        $this->ipv4 = trim($this->ipv4);
        $this->ipv6 = trim($this->ipv6);
        $this->hostmaster = trim($this->hostmaster);
        $hosts = array_values(array_unique(array_filter(array_map(
            fn ($h) => rtrim(mb_strtolower(trim($h)), '.'),
            preg_split('/[\s,]+/', $this->nameservers) ?: [],
        ))));

        $this->validate([
            'ipv4' => ['nullable', 'ipv4'],
            'ipv6' => ['nullable', 'ipv6'],
            'defaultTtl' => ['required', 'integer', 'min:'.DnsRecords::MIN_TTL, 'max:'.DnsRecords::MAX_TTL],
            'hostmaster' => ['nullable', 'email:rfc'],
            'refresh' => ['required', 'integer', 'min:1200', 'max:2419200'],
            'retry' => ['required', 'integer', 'min:180', 'max:2419200'],
            'expire' => ['required', 'integer', 'min:3600', 'max:4838400'],
            'minimum' => ['required', 'integer', 'min:60', 'max:86400'],
        ], [], [
            'ipv4' => 'IPv4 adresi',
            'ipv6' => 'IPv6 adresi',
            'defaultTtl' => 'varsayılan TTL',
            'hostmaster' => 'yönetici e-postası',
            'refresh' => 'yenileme',
            'retry' => 'yeniden deneme',
            'expire' => 'sona erme',
            'minimum' => 'negatif TTL',
        ]);
        foreach ($hosts as $h) {
            if (DnsRecords::hostname($h, 'invalid') === null || ! str_contains($h, '.')) {
                $this->addError('nameservers', "\"{$h}\" geçerli bir ad sunucusu değil; tam adı yazın, örneğin ns1.ornek.com.tr.");

                return;
            }
        }
        if (count($hosts) === 1) {
            $this->addError('nameservers', 'En az iki ad sunucusu yazın ya da alanı boş bırakın.');

            return;
        }

        // Each zone's name servers before the change, to update the zones
        // that still use them.
        $before = $this->applyToZones
            ? DnsZone::all()->mapWithKeys(fn (DnsZone $z) => [$z->id => $template->nameservers($z->name)])
            : collect();

        $settings->save([
            'nameservers' => $hosts,
            'ipv4' => $this->ipv4 ?: null,
            'ipv6' => $this->ipv6 ?: null,
            'default_ttl' => $this->defaultTtl,
            'hostmaster' => $this->hostmaster ?: null,
            'soa' => ['refresh' => $this->refresh, 'retry' => $this->retry, 'expire' => $this->expire, 'minimum' => $this->minimum],
        ]);
        $this->nameservers = implode(', ', $hosts);

        $updated = 0;
        foreach ($before as $id => $old) {
            $zone = DnsZone::with('records')->find($id);
            $new = $template->nameservers($zone->name);
            $current = $zone->nameservers();
            sort($current);
            sort($old);
            if ($current !== $old || $old === $new) {
                continue;
            }
            $zones->change($zone, function (DnsZone $zone) use ($new) {
                $ns = $zone->records()->where('name', '@')->where('type', 'NS');
                $ttl = (clone $ns)->value('ttl') ?? $zone->default_ttl;
                $ns->delete();
                foreach ($new as $host) {
                    $zone->records()->create(['name' => '@', 'type' => 'NS', 'content' => $host, 'ttl' => $ttl]);
                }
            });
            $updated++;
        }
        $this->applyToZones = false;

        $this->dispatch('toast', message: 'DNS ayarları kaydedildi.'.($updated ? " {$updated} bölgenin ad sunucuları güncellendi." : ''));
    }

    public function openAdd(): void
    {
        $this->openRecordForm(defaultTtl: $this->defaultTtl);
    }

    public function openEdit(int $index, DnsSettings $settings): void
    {
        $row = $settings->template()[$index] ?? null;
        if ($row !== null) {
            $this->openRecordForm($index, [...$row, 'ttl' => $row['ttl'] ?? $settings->defaultTtl()]);
        }
    }

    public function saveRecord(DnsSettings $settings): void
    {
        $rows = $settings->template();
        if ($this->recordKey !== null) {
            if (! isset($rows[$this->recordKey])) {
                return;
            }
            $this->recordType = $rows[$this->recordKey]['type'];
        }

        $typed = $this->recordValue;
        $this->recordValue = strtr($typed, self::SAMPLE);
        $record = $this->validatedRecord(self::SAMPLE['<domain>']);
        $this->recordValue = $typed;
        if ($record === null) {
            return;
        }
        $record['content'] = strtr($record['content'], array_flip(self::SAMPLE));
        if ($record['type'] === 'NS' && $record['name'] === '@') {
            $this->addError('recordName', 'Alan adının NS kayıtları yukarıdaki ad sunucusu ayarından gelir.');

            return;
        }
        $others = array_filter($rows, fn ($k) => $k !== $this->recordKey, ARRAY_FILTER_USE_KEY);
        if ($this->conflictsWith($record, $others)) {
            return;
        }

        $previous = $rows;
        if ($this->recordKey === null) {
            $rows[] = $record;
        } else {
            $rows[$this->recordKey] = $record;
        }
        $settings->save(['template' => array_values($rows)]);
        $this->editingRecord = false;
        $this->toastWithUndo($this->recordKey === null ? 'Şablona kayıt eklendi.' : 'Şablon kaydı güncellendi.', $previous);
    }

    public function deleteRow(int $index, DnsSettings $settings): void
    {
        $rows = $settings->template();
        if (! isset($rows[$index])) {
            return;
        }
        $previous = $rows;
        unset($rows[$index]);
        $settings->save(['template' => array_values($rows)]);
        $this->toastWithUndo('Şablon kaydı silindi.', $previous);
    }

    public function restoreDefaults(DnsSettings $settings): void
    {
        $previous = $settings->template();
        $settings->save(['template' => DnsSettings::DEFAULT_TEMPLATE]);
        $this->toastWithUndo('Şablon varsayılana döndü.', $previous);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    public function undoTemplate(array $rows, DnsSettings $settings): void
    {
        $settings->save(['template' => array_values($rows)]);
        $this->dispatch('toast', message: 'Değişiklik geri alındı.');
    }

    public function render(DnsSettings $settings, ZoneTemplate $template): View
    {
        return view('livewire.dns.settings', [
            'rows' => $settings->template(),
            'example' => $template->nameservers('ornek.com.tr'),
            'server' => Setting::get('dns.server', []),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $previous
     */
    private function toastWithUndo(string $message, array $previous): void
    {
        $this->dispatch('toast', message: $message, source: $this->getId(), undo: ['method' => 'undoTemplate', 'args' => [$previous]]);
    }
}
