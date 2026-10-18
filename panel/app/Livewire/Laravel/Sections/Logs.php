<?php

namespace App\Livewire\Laravel\Sections;

use App\Services\Laravel\LogParser;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;

/**
 * The application's own logs (storage/logs), newest entry first, with a
 * level filter, search and live follow.
 */
#[Lazy]
class Logs extends Section
{
    /** How much of the end of a log file is read. */
    public const BYTES = 512 * 1024;

    public string $file = '';

    /** Lowest level shown; empty for all. */
    public string $level = '';

    public string $search = '';

    public bool $follow = false;

    public function render(): View
    {
        $app = $this->app();
        $logs = $this->agent('laravel.logs', $app->agentApp() + ['file' => $this->file, 'bytes' => self::BYTES], []);
        $this->file = (string) ($logs['file'] ?? $this->file);
        $entries = collect(LogParser::parse((string) ($logs['content'] ?? '')))
            ->when($this->level !== '', fn ($c) => $c->filter(fn ($e) => LogParser::atLeast($e['level'], $this->level)))
            ->when(trim($this->search) !== '', fn ($c) => $c->filter(fn ($e) => mb_stripos($e['message'].' '.$e['detail'], trim($this->search)) !== false))
            ->take(300)
            ->values();

        return view('livewire.laravel.sections.logs', [
            'files' => $logs['files'] ?? [],
            'entries' => $entries,
            'truncated' => (bool) ($logs['truncated'] ?? false),
        ]);
    }
}
