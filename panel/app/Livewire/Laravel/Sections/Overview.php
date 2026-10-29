<?php

namespace App\Livewire\Laravel\Sections;

use App\Services\Laravel\MailEnv;
use App\Services\Laravel\PhpConstraint;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;

/**
 * What the application is and how it is doing: health, live release,
 * queue and its configuration as the application reports it.
 */
#[Lazy]
class Overview extends Section
{
    protected const VIEW_METHODS = ['checkHealth'];

    /** @var array<string, mixed>|null */
    public ?array $health = null;

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $this->checkHealth();
    }

    public function checkHealth(): void
    {
        $app = $this->app();
        if ($app !== null) {
            $this->health = $this->agent('laravel.health', ['domain' => $app->site->domain, 'path' => $app->health_path]);
        }
    }

    public function render(): View
    {
        $app = $this->app();
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        $about = $this->agent('laravel.probe', $app->agentApp() + ['kind' => 'about']);
        $workers = $app->workers()->get();
        $queues = $workers->isEmpty() ? null : $this->agent('laravel.probe', $app->agentApp() + [
            'kind' => 'queues',
            'queues' => $workers->flatMap(fn ($w) => collect($w->queues())->map(fn ($q) => [(string) $w->connection, $q]))->unique()->values()->all(),
        ]);
        $live = $app->deployments()->where('release', $app->current_release)->whereIn('status', ['succeeded', 'unhealthy'])->first();

        return view('livewire.laravel.sections.overview', [
            'app' => $app,
            'site' => $app->site,
            'status' => $status,
            'about' => $about,
            'queues' => $queues,
            'workers' => $workers,
            'live' => $live,
            'mailProblems' => is_array($about['mail'] ?? null) ? app(MailEnv::class)->problems($app->site, $about['mail']) : [],
            'canFixMail' => auth()->user()->canOnApp($app, 'operate'),
            'fits' => $app->php_requirement ? PhpConstraint::allows($app->php_requirement, $app->site->php_version) : null,
        ]);
    }
}
