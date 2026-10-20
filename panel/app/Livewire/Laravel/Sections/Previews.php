<?php

namespace App\Livewire\Laravel\Sections;

use App\Enums\DeploymentStatus;
use App\Enums\LaravelAppStatus;
use App\Models\LaravelPreview;
use App\Services\Laravel\EnvFile;
use App\Services\Laravel\Previews as PreviewService;
use App\Support\Badge;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;
use RuntimeException;

/**
 * Preview environments: a branch or pull request on its own subdomain,
 * opened by hand or by pull request webhooks and removed when the pull
 * request closes.
 */
#[Lazy]
class Previews extends Section
{
    public bool $creating = false;

    public string $branch = '';

    public string $envText = '';

    public int $limit = 5;

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $app = $this->app();
        $this->envText = (string) $app->preview_env;
        $this->limit = $app->preview_limit;
    }

    public function setting(string $name, mixed $value): void
    {
        $app = $this->app();
        $before = $app->{$name} ?? null;
        match ($name) {
            'previews_enabled', 'preview_seed' => $app->update([$name => (bool) $value]),
            default => null,
        };
        $this->dispatch('toast',
            message: $name === 'previews_enabled' ? ($value ? __('Pull request önizlemeleri açıldı.') : __('Pull request önizlemeleri kapatıldı.')) : __('Önizleme ayarı kaydedildi.'),
            source: $this->getId(),
            undo: ['method' => 'setting', 'args' => [$name, $before]],
        );
    }

    public function saveSettings(): void
    {
        $this->envText = str_replace("\r\n", "\n", trim($this->envText));
        $this->validate([
            'envText' => ['nullable', 'string', 'max:20000', function ($attr, $value, $fail) {
                foreach (preg_split('/\n/', (string) $value) as $i => $line) {
                    $line = trim($line);
                    if ($line !== '' && ! str_starts_with($line, '#') && ! preg_match('/^[A-Za-z_][A-Za-z0-9_]*=/', $line)) {
                        $fail(__('Satır :i: ANAHTAR=değer biçiminde yazın.', ['i' => ($i + 1)]));

                        return;
                    }
                }
            }],
            'limit' => ['required', 'integer', 'between:1,20'],
        ], [], ['limit' => __('önizleme sınırı')]);
        $this->app()->update(['preview_env' => $this->envText === '' ? null : $this->envText, 'preview_limit' => $this->limit]);
        $this->dispatch('toast', message: __('Önizleme ayarları kaydedildi; yeni önizlemelerde geçerli.'));
    }

    public function openCreate(): void
    {
        $this->branch = '';
        $this->resetValidation();
        $this->creating = true;
    }

    public function create(PreviewService $previews): void
    {
        $this->branch = trim($this->branch);
        $this->validate(['branch' => ['required', 'max:200', 'regex:~^[A-Za-z0-9][A-Za-z0-9._/-]*$~', 'not_regex:~\.\.~']],
            ['branch.regex' => __('Dal adı geçersiz.'), 'branch.not_regex' => __('Dal adı geçersiz.')], ['branch' => 'dal']);
        try {
            $preview = $previews->create($this->app(), $this->branch, auth()->user());
        } catch (RuntimeException $e) {
            $this->addError('branch', $e->getMessage());

            return;
        }
        $this->creating = false;
        $this->dispatch('toast', message: __(':domain hazırlanıyor.', ['domain' => $preview->domain()]));
    }

    public function redeploy(int $id, PreviewService $previews): void
    {
        $preview = $this->preview($id);
        if ($preview !== null) {
            $previews->redeploy($preview, by: auth()->user()->name);
            $this->dispatch('toast', message: __(':domain yeniden dağıtılıyor.', ['domain' => $preview->domain()]));
        }
    }

    public function retry(int $id, PreviewService $previews): void
    {
        $preview = $this->preview($id);
        // Only a preview whose site could not be made; a failed install is
        // retried from the preview's own page.
        if ($preview === null || $preview->status !== 'failed' || $preview->preview_app_id !== null) {
            return;
        }
        try {
            $previews->create($this->app(), $preview->branch, auth()->user(), $preview->pr_number);
        } catch (RuntimeException $e) {
            $this->agentError = $e->getMessage();
        }
    }

    public function delete(int $id, PreviewService $previews): void
    {
        $preview = $this->preview($id);
        if ($preview !== null) {
            $previews->delete($preview);
            $this->dispatch('toast', message: __(':domain siliniyor.', ['domain' => $preview->domain()]));
        }
    }

    /**
     * What a preview's row shows: the preview's own state until its
     * application exists, then the state of its install and deploys.
     */
    public static function state(LaravelPreview $preview): Badge
    {
        $app = $preview->previewApp;
        $deployment = $app?->latestDeployment;

        return match (true) {
            $preview->status === 'deleting' => new Badge(__('Siliniyor'), 'info', true),
            $preview->status === 'failed' => new Badge(__('Kurulamadı'), 'crit'),
            $app === null => new Badge(__('Hazırlanıyor'), 'info', true),
            $deployment?->status->isBusy() ?? false => new Badge($app->status === LaravelAppStatus::Installing ? __('Kuruluyor') : __('Dağıtılıyor'), 'info', true),
            $app->status === LaravelAppStatus::Failed => new Badge(__('Kurulamadı'), 'crit'),
            in_array($deployment?->status, [DeploymentStatus::Failed, DeploymentStatus::RolledBack], true) => new Badge(__('Dağıtım başarısız'), 'crit'),
            $deployment?->status === DeploymentStatus::Unhealthy => new Badge(__('Sağlıksız'), 'warn'),
            default => new Badge(__('Hazır'), 'ok'),
        };
    }

    private function preview(int $id): ?LaravelPreview
    {
        return LaravelPreview::where('laravel_app_id', $this->appId)->find($id);
    }

    public function render(): View
    {
        $app = $this->app();
        $previews = $app->previews()->with('previewApp.latestDeployment', 'previewApp.site.certificate', 'site')->get();
        $overrides = array_keys(EnvFile::values((string) $app->preview_env));

        return view('livewire.laravel.sections.previews', [
            'app' => $app,
            'previews' => $previews,
            'busy' => $previews->contains(fn (LaravelPreview $p) => self::state($p)->isBusy()),
            'operate' => auth()->user()->canOnApp($app, 'operate'),
            'available' => $app->repository !== null && $app->isManaged(),
            'fixed' => array_keys(PreviewService::ENV),
            'overrides' => $overrides,
        ]);
    }
}
