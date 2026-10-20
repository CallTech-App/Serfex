<?php

namespace App\Http\Controllers\Api\Panel;

use App\Enums\ZoneStatus;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Services\Dns\Zones;
use App\Support\DnsRecords;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DnsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => DnsZone::visibleTo($request->user())->with('site')->withCount('records')->orderBy('name')->get()->map(fn (DnsZone $z) => [
            'name' => $z->name,
            'status' => $z->status->value,
            'site' => $z->site?->domain,
            'records' => $z->records_count,
        ])->values()]);
    }

    public function show(Request $request, string $zone): JsonResponse
    {
        $zone = $this->find($request, $zone);

        return response()->json(['data' => [
            'name' => $zone->name,
            'status' => $zone->status->value,
            'site' => $zone->site?->domain,
            'nameservers' => $zone->nameservers(),
            'last_error' => $zone->last_error,
            'records' => $zone->records()->orderBy('name')->orderBy('type')->get()->map(fn (DnsRecord $r) => $this->record($r))->values(),
        ]]);
    }

    /**
     * POST /api/v1/dns/zones/{zone}/records {type, name, content, priority?, ttl?}
     */
    public function storeRecord(Request $request, Zones $zones, string $zone): JsonResponse
    {
        $zone = $this->find($request, $zone);
        if ($zone->status === ZoneStatus::Deleting) {
            return $this->refuse(__('Bu bölge siliniyor.'), 409);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(DnsRecords::TYPES)],
            'name' => ['nullable', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:4000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'ttl' => ['nullable', 'integer', 'min:'.DnsRecords::MIN_TTL, 'max:'.DnsRecords::MAX_TTL],
        ], [], ['type' => __('tür'), 'content' => __('değer')]);
        $name = DnsRecords::normalizeName((string) ($data['name'] ?? ''), $zone->name);
        if ($name === null) {
            return $this->refuse(__('Ana makine yalnızca harf, rakam, - ve _ içerebilir; örneğin www veya _dmarc.'));
        }
        [$content, $error] = DnsRecords::normalizeContent($data['type'], $data['content'], $zone->name);
        if ($error !== null) {
            return $this->refuse($error);
        }
        $withPriority = in_array($data['type'], DnsRecords::WITH_PRIORITY, true);
        $record = [
            'name' => $name,
            'type' => $data['type'],
            'content' => $content,
            'priority' => $withPriority ? (int) ($data['priority'] ?? 10) : null,
            'ttl' => (int) ($data['ttl'] ?? $zone->default_ttl ?? 3600),
        ];
        if ($record['type'] === 'CNAME' && $name === '@') {
            return $this->refuse(__('Alan adının kendisine CNAME eklenemez; A veya AAAA kaydı kullanın.'));
        }
        foreach ($zone->records()->where('name', $name)->get() as $o) {
            if ($o->type === $record['type'] && $o->content === $record['content'] && $o->priority === $record['priority']) {
                return $this->refuse(__('Bu kayıt zaten var.'), 409);
            }
            if ($record['type'] === 'CNAME' || $o->type === 'CNAME') {
                return $this->refuse($record['type'] === 'CNAME'
                    ? __('Bu ana makinede başka kayıtlar var; CNAME tek başına olmalı.')
                    : __('Bu ana makinede CNAME kaydı var; yanına başka kayıt eklenemez.'), 409);
            }
        }
        $created = null;
        $zones->change($zone, function (DnsZone $zone) use ($record, &$created) {
            $created = $zone->records()->create($record);
            // PowerDNS keeps one TTL per name and type.
            $zone->records()->where('name', $record['name'])->where('type', $record['type'])->update(['ttl' => $record['ttl']]);
        });
        $this->audit($request, __('Alan Adları ve DNS · :name', ['name' => $zone->name]), __(':type kaydı eklendi: :name', ['type' => $record['type'], 'name' => DnsRecords::fqdn($name, $zone->name)]), route('dns.show', $zone, false));

        return response()->json(['data' => $this->record($created)], 201);
    }

    public function destroyRecord(Request $request, Zones $zones, string $zone, int $record): JsonResponse
    {
        $zone = $this->find($request, $zone);
        $found = $zone->records()->find($record) ?? $this->missing(__('Bu bölgede böyle bir kayıt yok.'));
        if ($found->name === '@' && $found->type === 'NS' && $zone->records()->where('name', '@')->where('type', 'NS')->count() <= 1) {
            return $this->refuse(__('Bölgede en az bir NS kaydı kalmalı.'), 409);
        }
        $zones->change($zone, fn (DnsZone $zone) => $zone->records()->whereKey($found->id)->delete());
        $this->audit($request, __('Alan Adları ve DNS · :name', ['name' => $zone->name]), __(':type kaydı silindi: :name', ['type' => $found->type, 'name' => DnsRecords::fqdn($found->name, $zone->name)]), route('dns.show', $zone, false));

        return response()->json(null, 204);
    }

    private function find(Request $request, string $name): DnsZone
    {
        return DnsZone::visibleTo($request->user())->with('site')->where('name', mb_strtolower(rtrim($name, '.')))->first()
            ?? $this->missing(__(':zone adında bir DNS bölgesi yok.', ['zone' => $name]));
    }

    /**
     * @return array<string, mixed>
     */
    private function record(DnsRecord $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'type' => $r->type, 'content' => $r->content, 'priority' => $r->priority, 'ttl' => $r->ttl];
    }
}
