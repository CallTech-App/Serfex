<?php

namespace App\Livewire\Services;

use App\Models\LaravelApp;
use App\Services\Tuning\Optimization;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Hizmetler → Redis (site önbellekleri): the optimization suggestions of
 * the Laravel applications' own Redis instances, which are not one
 * service of their own.
 */
#[Layout('components.layouts.app')]
class RedisTuningPage extends Component
{
    public function render(): View
    {
        return view('livewire.services.redis-tuning-page', [
            'apps' => LaravelApp::with('site')->whereNotNull('redis_port')->get()->sortBy(fn ($a) => $a->site?->domain)->values(),
            'unit' => Optimization::REDIS,
        ])->title(__(':name · Hizmetler · Serfex', ['name' => __('Redis (site önbellekleri)')]));
    }
}
