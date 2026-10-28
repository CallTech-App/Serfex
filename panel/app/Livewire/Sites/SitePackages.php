<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Models\Site;
use App\Models\SiteCommand;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\CommandLine;
use App\Services\Laravel\LaravelApps;
use App\Services\Sites\SiteTools;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Json;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Composer ve Node.js on any site: the folders with a composer.json or a
 * package.json, one-click and typed commands run as the site's account in
 * the chosen folder with live output, and the Node.js line the site's
 * commands use.
 */
#[Layout('components.layouts.app')]
#[ForCustomers('shell')]
class SitePackages extends Component
{
    public int $siteId;

    /** composer or node. */
    #[Url(as: 'tool')]
    public string $tool = 'composer';

    /** The folder, relative to the site directory. */
    public string $path = '';

    public string $packageManager = 'npm';

    public string $command = '';

    public string $node = '';

    public ?int $shownId = null;

    /** @var array{composer: string, node: array<string, string>, default_node: string, projects: list<array<string, mixed>>}|null */
    public ?array $tools = null;

    public function mount(int $site, SiteTools $tools): void
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $site->id;
        if (! in_array($this->tool, ['composer', 'node'], true)) {
            $this->tool = 'composer';
        }
        $this->node = (string) $site->node_version;
        $this->tools = $tools->scan($site->load('systemUser'));
        $this->pickProject();
        $this->shownId = $site->commands()->value('id');
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer.package', 'laravelApp')->findOrFail($this->siteId);
    }

    public function updatedTool(): void
    {
        if (! in_array($this->tool, ['composer', 'node'], true)) {
            $this->tool = 'composer';
        }
        $this->command = '';
        $this->resetValidation();
        $this->pickProject();
    }

    public function updatedPath(): void
    {
        if ($this->tool === 'node' && ($project = $this->project()) !== null) {
            $this->packageManager = $project['manager'] ?? 'npm';
        }
    }

    /**
     * The first folder that has the tab's project file.
     */
    private function pickProject(): void
    {
        $key = $this->tool === 'composer' ? 'composer' : 'node';
        $first = collect($this->tools['projects'] ?? [])->first(fn ($p) => $p[$key] ?? false);
        if ($first !== null) {
            $this->path = (string) $first['path'];
            $this->updatedPath();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function project(): ?array
    {
        return collect($this->tools['projects'] ?? [])->firstWhere('path', $this->path);
    }

    public function refreshScan(SiteTools $tools): void
    {
        $this->tools = $tools->scan($this->site());
    }

    public function run(SiteTools $tools): void
    {
        $this->command = trim($this->command);
        $this->validate([
            'command' => ['required', 'max:4000'],
            'packageManager' => ['in:'.implode(',', LaravelApps::PACKAGE_MANAGERS)],
            'path' => ['max:200', 'regex:~^(?:[A-Za-z0-9._-]+(?:/[A-Za-z0-9._-]+)*)?$~', 'not_regex:~(^|/)\.\.?(/|$)~'],
        ], ['command.required' => __('Bir komut yazın.'), 'path.*' => __('Site klasöründe bir klasör seçin.')]);
        $line = $this->withoutPrefix($this->command);
        try {
            CommandLine::split($line);
        } catch (InvalidArgumentException $e) {
            $this->addError('command', $e->getMessage());

            return;
        }
        $site = $this->site();
        if ($site->commands()->whereIn('status', ['queued', 'running'])->count() >= SiteTools::MAX_RUNNING) {
            $this->addError('command', __('Aynı anda en fazla 3 komut çalışabilir; birinin bitmesini bekleyin.'));

            return;
        }
        $tool = $this->tool === 'node' ? $this->packageManager : 'composer';
        $this->shownId = $tools->run($site, $tool, $this->path, $line, auth()->user())->id;
    }

    public function pick(string $command): void
    {
        $this->command = $command;
    }

    /**
     * Saves the Node.js line of the site's commands; empty is the
     * server's default.
     */
    public function saveNode(): void
    {
        $site = $this->site();
        $installed = array_keys($this->tools['node'] ?? []);
        if ($this->node !== '' && (! preg_match(SiteTools::NODE_LINE, $this->node) || ! in_array($this->node, array_map('strval', $installed), true))) {
            $this->node = (string) $site->node_version;

            return;
        }
        $before = (string) $site->node_version;
        if ($before === $this->node) {
            return;
        }
        $site->update(['node_version' => $this->node === '' ? null : $this->node]);
        $this->dispatch('toast',
            message: $this->node === '' ? __('Komutlar sunucunun varsayılan Node.js sürümüyle çalışacak.') : __('Komutlar Node.js :line ile çalışacak.', ['line' => $this->node]),
            source: $this->getId(),
            undo: ['method' => 'undoNode', 'args' => [$before]],
        );
    }

    public function undoNode(string $line): void
    {
        $this->node = $line;
        $this->saveNode();
    }

    public function show(int $id): void
    {
        $this->shownId = SiteCommand::where('site_id', $this->siteId)->find($id)?->id;
    }

    /**
     * The log of one of the site's commands from $offset on.
     *
     * @return array{data: string, offset: int, more: bool, done: bool, error?: string}|null
     */
    #[Json, Renderless]
    public function readRun(string $run, int $offset = 0): ?array
    {
        $site = Site::visibleTo(auth()->user())->findOrFail($this->siteId);
        $command = SiteCommand::where('site_id', $site->id)->where('run', $run)->first();
        if ($command === null) {
            return null;
        }
        try {
            $chunk = app(AgentClient::class)->call('laravel.run.read', ['domain' => $site->domain, 'run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => false, 'error' => __('Sunucu ajanına ulaşılamıyor.')];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return [
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $next < (int) ($chunk['size'] ?? 0),
            'done' => ! $command->isBusy(),
        ];
    }

    /**
     * "composer install" or "npm run build" pasted whole works too.
     */
    private function withoutPrefix(string $command): string
    {
        $prefix = $this->tool === 'composer' ? 'composer' : 'npm|pnpm|yarn|bun';

        return trim((string) preg_replace('~^(?:'.$prefix.')\s+~', '', $command));
    }

    public function render(): View
    {
        $site = $this->site();
        $key = $this->tool === 'composer' ? 'composer' : 'node';
        $recent = $site->commands()->limit(10)->get();
        $project = $this->project();

        return view('livewire.sites.packages', [
            'site' => $site,
            'projects' => collect($this->tools['projects'] ?? [])->filter(fn ($p) => $p[$key] ?? false)->values(),
            'project' => $project,
            'quick' => [
                ...SiteTools::QUICK[$key],
                ...($key === 'node' ? array_map(fn ($s) => 'run '.$s, array_diff($project['scripts'] ?? [], ['build'])) : []),
            ],
            'recent' => $recent,
            'shown' => $this->shownId ? ($recent->firstWhere('id', $this->shownId) ?? SiteCommand::where('site_id', $site->id)->find($this->shownId)) : null,
            'busy' => $recent->contains(fn (SiteCommand $c) => $c->isBusy()),
            'directory' => '/home/'.$site->systemUser->name.'/web/'.$site->domain.($this->path !== '' ? '/'.$this->path : ''),
        ])->title(__('Composer ve Node.js · :domain · Serfex', ['domain' => $site->domain]));
    }
}
