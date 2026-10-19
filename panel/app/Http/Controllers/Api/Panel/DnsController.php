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
    public function index(): JsonResponse
    {
        return response()->json(['data' => DnsZone::with('site')->withCount('records')->orderBy('name')->get()->map(fn (DnsZone $z) => [
            'name' => $z->name,
            'status' => $z->status->value,
            'site' => $z->site?->domain,
            'records' => $z->records_count,
        ])->values()]);
    }

    public function show(string $zone): JsonResponse
    {
        $zone = $this->find($zone);

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
        $zone = $this->find($zone);
        if ($zone->status === ZoneStatus::Deleting) {
            return $this->refuse('This zone is being deleted.', 409);
        }
        $data = $request->validate([
            'type' => ['required', Rule::in(DnsRecords::TYPES)],
            'name' => ['nullable', 'string', 'max:253'],
            'content' => ['required', 'string', 'max:4000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'ttl' => ['nullable', 'integer', 'min:'.DnsRecords::MIN_TTL, 'max:'.DnsRecords::MAX_TTL],
        ]);
        $name = DnsRecords::normalizeName((string) ($data['name'] ?? ''), $zone->name);
        if ($name === null) {
            return $this->refuse('The name may hold letters, digits, - and _ only, such as www or _dmarc.');
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
            return $this->refuse('A CNAME cannot be at the zone apex; use A or AAAA.');
        }
        foreach ($zone->records()->where('name', $name)->get() as $o) {
            if ($o->type === $record['type'] && $o->content === $record['content'] && $o->priority === $record['priority']) {
                return $this->refuse('This record already exists.', 409);
            }
            if ($record['type'] === 'CNAME' || $o->type === 'CNAME') {
                return $this->refuse('A CNAME must stand alone at its name.', 409);
            }
        }
        $created = null;
        $zones->change($zone, function (DnsZone $zone) use ($record, &$created) {
            $created = $zone->records()->create($record);
            // PowerDNS keeps one TTL per name and type.
            $zone->records()->where('name', $record['name'])->where('type', $record['type'])->update(['ttl' => $record['ttl']]);
        });
        $this->audit($request, 'Alan Adları ve DNS · '.$zone->name, "{$record['type']} kaydı eklendi: ".DnsRecords::fqdn($name, $zone->name), route('dns.show', $zone, false));

        return response()->json(['data' => $this->record($created)], 201);
    }

    public function destroyRecord(Request $request, Zones $zones, string $zone, int $record): JsonResponse
    {
        $zone = $this->find($zone);
        $found = $zone->records()->findOrFail($record);
        if ($found->name === '@' && $found->type === 'NS' && $zone->records()->where('name', '@')->where('type', 'NS')->count() <= 1) {
            return $this->refuse('The zone must keep at least one NS record.', 409);
        }
        $zones->change($zone, fn (DnsZone $zone) => $zone->records()->whereKey($found->id)->delete());
        $this->audit($request, 'Alan Adları ve DNS · '.$zone->name, "{$found->type} kaydı silindi: ".DnsRecords::fqdn($found->name, $zone->name), route('dns.show', $zone, false));

        return response()->json(null, 204);
    }

    private function find(string $name): DnsZone
    {
        return DnsZone::with('site')->where('name', mb_strtolower(rtrim($name, '.')))->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function record(DnsRecord $r): array
    {
        return ['id' => $r->id, 'name' => $r->name, 'type' => $r->type, 'content' => $r->content, 'priority' => $r->priority, 'ttl' => $r->ttl];
    }
}
