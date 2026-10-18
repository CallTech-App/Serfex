<?php

namespace App\Livewire\Laravel\Sections;

use App\Models\LaravelEnvVersion;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\EnvFile;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Lazy;

/**
 * The application's .env, as a table of variables or as text. Saving
 * keeps the previous file in the history and rebuilds the config cache.
 */
#[Lazy]
class Environment extends Section
{
    /** How many earlier versions are kept. */
    public const HISTORY = 30;

    /** rows or text */
    public string $mode = 'rows';

    /** @var list<array{key: string, value: string}> */
    public array $rows = [];

    public string $text = '';

    /** The file as loaded, to tell what changed. */
    public ?string $original = null;

    public ?string $example = null;

    public bool $missing = false;

    public bool $comparing = false;

    public bool $showingHistory = false;

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $this->load();
    }

    public function load(): void
    {
        $files = $this->agent('laravel.env.get', $this->app()->agentApp());
        if ($files === null) {
            return;
        }
        $this->missing = $files['env'] === null;
        $this->original = (string) ($files['env'] ?? '');
        $this->example = $files['example'];
        $this->loadContent($this->original);
        $this->resetValidation();
    }

    public function updatedMode(string $mode): void
    {
        // Carry edits over when switching between the two views.
        if ($mode === 'text') {
            $this->text = EnvFile::apply($this->text, $this->values());
        } else {
            $this->rows = $this->rowsOf($this->text);
        }
    }

    public function addRow(string $key = ''): void
    {
        $this->rows[] = ['key' => $key, 'value' => ''];
    }

    public function removeRow(int $i): void
    {
        unset($this->rows[$i]);
        $this->rows = array_values($this->rows);
    }

    /**
     * Adds the keys .env.example has and .env lacks, empty.
     */
    public function addMissing(): void
    {
        $have = array_column($this->rows, 'key');
        foreach (EnvFile::compare(EnvFile::apply($this->text, $this->values()), $this->example)['missing'] as $key) {
            if (! in_array($key, $have, true)) {
                $this->rows[] = ['key' => $key, 'value' => (string) (EnvFile::values((string) $this->example)[$key] ?? '')];
            }
        }
        $this->comparing = false;
    }

    public function save(AgentClient $agent): void
    {
        $app = $this->app();
        if ($this->mode === 'rows') {
            foreach ($this->rows as $i => $row) {
                $this->rows[$i]['key'] = strtoupper(trim($row['key']));
            }
            $this->validate([
                'rows.*.key' => ['required', 'regex:/^[A-Z_][A-Z0-9_]*$/', 'distinct'],
                'rows.*.value' => ['nullable', 'string', 'max:65535'],
            ], [
                'rows.*.key.required' => 'Anahtar boş olamaz.',
                'rows.*.key.regex' => 'Anahtar büyük harf, rakam ve _ içerebilir.',
                'rows.*.key.distinct' => 'Bu anahtar iki kez var.',
            ]);
            $content = EnvFile::apply($this->text, $this->values());
        } else {
            $this->validate(['text' => ['nullable', 'string', 'max:262144']]);
            $content = str_replace("\r\n", "\n", $this->text);
        }

        $before = $this->original ?? '';
        if ($content === $before) {
            $this->dispatch('toast', message: 'Değişiklik yok.');

            return;
        }
        try {
            $result = $agent->call('laravel.env.set', $app->agentApp() + ['content' => $content], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = '.env kaydedilemedi: '.self::agentMessage($e);

            return;
        }
        $this->agentError = null;
        $version = $app->envVersions()->create([
            'content' => $before,
            'summary' => EnvFile::summary($before, $content),
            'user_name' => auth()->user()?->name,
        ]);
        $app->envVersions()->whereNotIn('id', $app->envVersions()->limit(self::HISTORY)->pluck('id'))->delete();
        $this->original = $content;
        $this->missing = false;
        $this->loadContent($content);
        $this->dispatch('toast',
            message: ($result['cached'] ?? false) ? '.env kaydedildi, config:cache yenilendi.' : '.env kaydedildi.',
            source: $this->getId(),
            undo: ['method' => 'restore', 'args' => [$version->id]],
        );
    }

    /**
     * Puts an earlier version of the .env back (it is saved like an edit,
     * so the current file goes into the history).
     */
    public function restore(int $versionId, AgentClient $agent): void
    {
        $version = LaravelEnvVersion::where('laravel_app_id', $this->appId)->find($versionId);
        if ($version === null) {
            return;
        }
        $this->mode = 'text';
        $this->text = $version->content;
        $this->showingHistory = false;
        $this->save($agent);
    }

    public function render(): View
    {
        $app = $this->app();
        $current = $this->original === null ? '' : ($this->mode === 'rows' ? EnvFile::apply($this->text, $this->values()) : $this->text);

        return view('livewire.laravel.sections.environment', [
            'app' => $app,
            'diff' => EnvFile::compare($current, $this->example),
            'history' => $this->showingHistory ? $app->envVersions()->limit(self::HISTORY)->get() : collect(),
        ]);
    }

    private function loadContent(string $content): void
    {
        $this->text = $content;
        $this->rows = $this->rowsOf($content);
    }

    /**
     * @return list<array{key: string, value: string}>
     */
    private function rowsOf(string $content): array
    {
        $rows = [];
        foreach (EnvFile::values($content) as $key => $value) {
            $rows[] = ['key' => $key, 'value' => $value];
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function values(): array
    {
        $values = [];
        foreach ($this->rows as $row) {
            $key = strtoupper(trim($row['key']));
            if ($key !== '') {
                $values[$key] = (string) $row['value'];
            }
        }

        return $values;
    }
}
