<?php

namespace App\Http\Controllers\Api\Panel;

use App\Models\Package;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;

class PackagesController extends ApiController
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Package::withCount('customers')->orderBy('id')->get()->map(fn (Package $p) => [
            'id' => $p->id,
            'name' => Locales::label($p->name),
            'default' => (bool) $p->is_default,
            'customers' => $p->customers_count,
            'limits' => collect(Package::LIMITS)->mapWithKeys(fn ($l, $key) => [$key => $p->limit($key)]),
            'features' => collect(Package::FEATURES)->mapWithKeys(fn ($f, $key) => [$key => $p->allows($key)]),
        ])->values()]);
    }
}
