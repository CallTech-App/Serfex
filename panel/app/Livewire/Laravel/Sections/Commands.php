<?php

namespace App\Livewire\Laravel\Sections;

use App\Livewire\Laravel\ReadsRunLogs;
use App\Models\LaravelCommand;
use App\Services\Laravel\CommandLine;
use App\Services\Laravel\LaravelApps;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;

/**
 * Artisan, Composer and Node.js commands run as the site's account in the
 * application directory, with live output and saved commands.
 */
class Commands extends Section
{
    use ReadsRunLogs;

    /** Offered until the user saves their own. */
    public const DEFAULT_SAVED = [
        'artisan' => ['migrate --force', 'optimize:clear', 'about', 'queue:failed'],
        'composer' => ['install --no-dev --optimize-autoloader', 'outdated --direct'],
        'node' => ['run build'],
    ];

    /** artisan, composer or node (with $packageManager). */
    public string $tool = 'artisan';

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
        if ($app->commands()->whereIn('status', ['queued', 'running'])->count() >= 3) {
            $this->addError('command', 'Aynı anda en fazla 3 komut çalışabilir; birinin bitmesini bekleyin.');

            return;
        }
        $command = app(LaravelApps::class)->runCommand($app, $tool, $line, auth()->user());
        $this->shownId = $command->id;
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
        if (! in_array($command, $saved[$this->tool], true)) {
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

        return view('livewire.laravel.sections.commands', [
            'app' => $app,
            'saved' => $this->savedCommands($app->saved_commands ?? [])[$this->tool],
            'recent' => $recent,
            'shown' => $this->shownId ? ($recent->firstWhere('id', $this->shownId) ?? LaravelCommand::find($this->shownId)) : null,
            'busy' => $recent->contains(fn (LaravelCommand $c) => in_array($c->status, ['queued', 'running'], true)),
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
