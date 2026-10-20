<?php

namespace App\Http\Controllers\Api\Panel;

use App\Services\System\SystemServices;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Hizmetler: the server's services, their journal, and start, stop and
 * restart. A restart tests the configuration first, as in the panel; the
 * services the server and the panel live on cannot be stopped.
 */
class ServicesController extends ApiController
{
    public function __construct(private readonly SystemServices $services) {}

    public function index(): JsonResponse
    {
        $error = null;
        $list = $this->services->list($error);
        if ($list === null) {
            return $this->refuse($error ?? __('Sunucu ajanına ulaşılamıyor.'), 503);
        }

        return response()->json(['data' => array_map(fn (array $s) => $this->service($s), $list)]);
    }

    public function show(string $unit): JsonResponse
    {
        return response()->json(['data' => $this->service($this->find($unit)) + ['affects' => $this->services->affects($unit)]]);
    }

    /**
     * GET /api/v1/services/{unit}/logs[?lines=<n>]
     */
    public function logs(Request $request, string $unit): JsonResponse
    {
        $this->find($unit);
        $lines = $this->services->logs($unit, min(1000, max(1, (int) $request->query('lines', 50))));
        if (is_string($lines)) {
            return $this->refuse($lines, 502);
        }

        return response()->json(['data' => ['unit' => $unit, 'lines' => $lines]]);
    }

    /**
     * POST /api/v1/services/{unit}/{start|stop|restart}
     */
    public function act(Request $request, string $unit, string $action): JsonResponse
    {
        $service = $this->find($unit);
        if ($action === 'stop' && ($service['protected'] ?? '') !== '') {
            return $this->refuse($service['protected'], 409);
        }
        $result = $this->services->act($unit, $action);
        if (is_string($result)) {
            return $this->refuse($result, 409);
        }
        $name = $result['name'] ?? $service['name'] ?? $unit;
        $this->audit($request, __('Hizmetler'), match ($action) {
            'start' => __(':name başlatıldı.', ['name' => $name]),
            'stop' => __(':name durduruldu.', ['name' => $name]),
            default => __(':name yeniden başlatıldı.', ['name' => $name]),
        }, route('services.index', [], false));
        $warning = in_array($action, ['start', 'restart'], true) && ($result['state'] ?? '') !== 'active'
            ? __(':name çalışmıyor; ayrıntılar için günlüğüne bakın.', ['name' => $name]) : null;

        return response()->json(['data' => $this->service($result + $service), 'warning' => $warning]);
    }

    /**
     * @return array<string, mixed>
     */
    private function find(string $unit): array
    {
        $error = null;
        if ($this->services->list($error) === null) {
            throw new HttpResponseException($this->refuse($error ?? __('Sunucu ajanına ulaşılamıyor.'), 503));
        }

        return $this->services->find($unit) ?? $this->missing(__(':unit adında bir hizmet yok.', ['unit' => $unit]));
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, mixed>
     */
    private function service(array $s): array
    {
        return [
            'unit' => $s['unit'],
            'name' => $s['name'] ?? $s['unit'],
            'group' => $s['group'] ?? null,
            'state' => $s['state'] ?? null,
            'sub_state' => $s['sub_state'] ?? null,
            'enabled' => $s['enabled'] ?? null,
            'since' => $s['since'] ?? null,
            'pid' => $s['pid'] ?? null,
            'restarts' => $s['restarts'] ?? 0,
            'cpu' => $s['cpu'] ?? null,
            'memory' => $s['memory'] ?? null,
            'protected' => ($s['protected'] ?? '') !== '' ? $s['protected'] : null,
        ];
    }
}
