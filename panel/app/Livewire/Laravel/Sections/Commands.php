<?php

namespace App\Livewire\Laravel\Sections;

use App\Livewire\Laravel\ReadsRunLogs;
use App\Models\LaravelCommand;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\CommandLine;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Artisan, Composer and Node.js commands run as the site's account in the
 * application directory, with live output and saved commands, and Tinker
 * sessions that keep their state between inputs.
 */
class Commands extends Section
{
    protected const VIEW_METHODS = ['show'];

    /** The package feature a customer's application needs for this section. */
    public const FEATURE = 'shell';

    use ReadsRunLogs;

    /** Offered until the user saves their own. */
    public const DEFAULT_SAVED = [
        'artisan' => ['migrate --force', 'optimize:clear', 'about', 'queue:failed'],
        'composer' => ['install --no-dev --optimize-autoloader', 'outdated --direct'],
        'node' => ['run build'],
    ];

    /** artisan, composer, node (with $packageManager) or tinker. */
    public string $tool = 'artisan';

    /** Code to send to the open Tinker session. */
    public string $code = '';

    public string $packageManager = 'npm';

    public string $command = '';

    public ?int $shownId = null;

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $app = $this->app();
        $this->packageManager = $app->package_manager ?? 'npm';
        $this->shownId = $app->commands()->value('id');
    }

    public function updatedTool(): void
    {
        $this->command = '';
        $this->resetValidation();
    }

    public function run(): void
    {
        if ($this->tool === 'tinker') {
            $this->send();

            return;
        }
        $this->command = trim($this->command);
        $tool = $this->agentTool();
        $this->validate([
            'command' => ['required', 'max:4000'],
            'packageManager' => ['in:'.implode(',', LaravelApps::PACKAGE_MANAGERS)],
        ], ['command.required' => 'Bir komut yazın.']);
        $line = $this->withoutPrefix($this->command);
        try {
            CommandLine::split($line);
        } catch (InvalidArgumentException $e) {
            $this->addError('command', $e->getMessage());

            return;
        }
        $app = $this->app();
        if ($app->commands()->where('tool', '!=', 'tinker')->whereIn('status', ['queued', 'running'])->count() >= 3) {
            $this->addError('command', 'Aynı anda en fazla 3 komut çalışabilir; birinin bitmesini bekleyin.');

            return;
        }
        $command = app(LaravelApps::class)->runCommand($app, $tool, $line, auth()->user());
        $this->shownId = $command->id;
    }

    /**
     * Starts a Tinker session for the current account.
     */
    public function startTinker(AgentClient $agent): void
    {
        $app = $this->app();
        if ($this->session() !== null) {
            return;
        }
        $session = $app->commands()->create([
            'user_id' => auth()->id(),
            'tool' => 'tinker',
            'command' => 'tinker',
            'run' => 'tinker-'.Str::lower(Str::random(12)),
            'status' => 'running',
        ]);
        try {
            $agent->call('laravel.tinker.start', $app->agentApp() + ['session' => $session->run], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $session->update(['status' => 'failed', 'error' => $e->reason ?: $e->getMessage(), 'finished_at' => now()]);
        }
        $this->shownId = $session->id;
    }

    public function send(): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        $this->validate(['code' => ['required', 'string', 'max:60000']], ['code.required' => 'Çalıştırılacak PHP kodunu yazın.']);
        try {
            $state = app(AgentClient::class)->call('laravel.tinker.send', ['domain' => $this->app()->site->domain, 'session' => $session->run, 'code' => $this->code]);
        } catch (AgentException $e) {
            $this->addError('code', self::agentMessage($e));

            return;
        }
        if (! ($state['running'] ?? false)) {
            $session->update(['status' => 'finished', 'finished_at' => now()]);
            $this->addError('code', 'Oturum kapanmış; yeni bir oturum başlatın.');

            return;
        }
        $this->code = '';
    }

    public function stopTinker(AgentClient $agent): void
    {
        $session = $this->session();
        if ($session === null) {
            return;
        }
        try {
            $agent->call('laravel.tinker.stop', ['domain' => $this->app()->site->domain, 'session' => $session->run]);
        } catch (AgentException) {
        }
        $session->update(['status' => 'finished', 'finished_at' => now()]);
    }

    /**
     * The current account's open Tinker session.
     */
    private function session(): ?LaravelCommand
    {
        return LaravelCommand::where('laravel_app_id', $this->appId)->where('tool', 'tinker')
            ->where('status', 'running')->where('user_id', auth()->id())->latest('id')->first();
    }

    public function show(int $id): void
    {
        $this->shownId = LaravelCommand::where('laravel_app_id', $this->appId)->find($id)?->id;
    }

    public function pick(string $command): void
    {
        $this->command = $command;
    }

    public function saveCommand(): void
    {
        $command = $this->withoutPrefix(trim($this->command));
        if ($command === '') {
            return;
        }
        $app = $this->app();
        $saved = $this->savedCommands($app->saved_commands ?? []);
        if (isset($saved[$this->tool]) && ! in_array($command, $saved[$this->tool], true)) {
            $saved[$this->tool][] = $command;
            $app->update(['saved_commands' => $saved]);
        }
        $this->dispatch('toast', message: 'Komut kaydedildi.');
    }

    public function forget(string $command): void
    {
        $app = $this->app();
        $saved = $this->savedCommands($app->saved_commands ?? []);
        $saved[$this->tool] = array_values(array_diff($saved[$this->tool], [$command]));
        $app->update(['saved_commands' => $saved]);
        $this->dispatch('toast',
            message: 'Kayıtlı komut silindi.',
            source: $this->getId(),
            undo: ['method' => 'restoreSaved', 'args' => [$this->tool, $command]],
        );
    }

    public function restoreSaved(string $tool, string $command): void
    {
        $app = $this->app();
        $saved = $this->savedCommands($app->saved_commands ?? []);
        if (isset($saved[$tool]) && ! in_array($command, $saved[$tool], true)) {
            $saved[$tool][] = $command;
            $app->update(['saved_commands' => $saved]);
        }
    }

    public function render(): View
    {
        $app = $this->app();
        $recent = $app->commands()->limit(10)->get();

        $session = $this->tool === 'tinker' ? $this->session() : null;

        return view('livewire.laravel.sections.commands', [
            'app' => $app,
            'session' => $session,
            'saved' => $this->savedCommands($app->saved_commands ?? [])[$this->tool] ?? [],
            'recent' => $recent,
            'shown' => $this->shownId ? ($recent->firstWhere('id', $this->shownId) ?? LaravelCommand::find($this->shownId)) : null,
            'busy' => $recent->contains(fn (LaravelCommand $c) => $c->tool !== 'tinker' && in_array($c->status, ['queued', 'running'], true)),
        ]);
    }

    /**
     * "php artisan migrate" pasted whole works too.
     */
    private function withoutPrefix(string $command): string
    {
        $prefix = match ($this->tool) {
            'artisan' => '(php\s+)?artisan',
            'composer' => 'composer',
            default => 'npm|pnpm|yarn|bun',
        };

        return trim((string) preg_replace('~^(?:'.$prefix.')\s+~', '', $command));
    }

    private function agentTool(): string
    {
        return $this->tool === 'node' ? $this->packageManager : $this->tool;
    }

    /**
     * Saved commands per tool; the defaults until the user changes them.
     *
     * @param  array<string, list<string>>  $saved
     * @return array<string, list<string>>
     */
    private function savedCommands(array $saved): array
    {
        $out = [];
        foreach (self::DEFAULT_SAVED as $tool => $defaults) {
            $out[$tool] = array_key_exists($tool, $saved) ? array_values($saved[$tool]) : $defaults;
        }

        return $out;
    }
}
