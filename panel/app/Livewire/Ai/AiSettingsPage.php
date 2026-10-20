<?php

namespace App\Livewire\Ai;

use App\Models\AiUsage;
use App\Models\Setting;
use App\Services\Ai\AiSettings;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\ClaudeException;
use App\Services\Ai\Models;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Servik AI settings: on or off, the Claude API key and model, whether it
 * may block and limit by itself, a monthly spending cap, and what it has
 * cost so far.
 */
#[Layout('components.layouts.app')]
#[Title('Servik AI ayarları · Servik')]
class AiSettingsPage extends Component
{
    public bool $enabled = true;

    /** A new key; empty keeps the saved one. */
    public string $key = '';

    public string $model = Models::DEFAULT;

    public bool $auto = true;

    public string $budget = '';

    /** Result of "Bağlantıyı dene": [ok, message]. */
    public ?array $check = null;

    public function mount(AiSettings $settings): void
    {
        $this->enabled = $settings->enabled();
        $this->model = $settings->model();
        $this->auto = $settings->autoActions();
        $budget = $settings->budget();
        $this->budget = $budget === null ? '' : rtrim(rtrim(number_format($budget, 2, '.', ''), '0'), '.');
    }

    public function save(AiSettings $settings): void
    {
        $this->key = trim($this->key);
        $this->budget = str_replace(',', '.', trim($this->budget));
        $this->validate([
            'key' => ['nullable', 'string', 'max:300', 'regex:/^sk-ant-[A-Za-z0-9_-]+$/'],
            'model' => ['required', 'in:'.implode(',', array_keys(Models::ALL))],
            'budget' => ['nullable', 'numeric', 'min:0', 'max:100000'],
        ], ['key.regex' => __('Claude API anahtarları sk-ant- ile başlar.')], ['key' => __('API anahtarı'), 'budget' => __('harcama sınırı')]);

        $previous = $this->current($settings);
        Setting::put('ai.enabled', $this->enabled);
        Setting::put('ai.model', $this->model);
        Setting::put('ai.auto_actions', $this->auto);
        Setting::put('ai.budget', $this->budget === '' || (float) $this->budget <= 0 ? null : (float) $this->budget);
        if ($this->key !== '') {
            $settings->setApiKey($this->key);
            $this->key = '';
        }
        $this->check = null;
        $this->dispatch('toast', message: __('Servik AI ayarları kaydedildi.'), source: $this->getId(),
            undo: ['method' => 'undoSave', 'args' => [encrypt($previous)]]);
    }

    public function undoSave(string $previous, AiSettings $settings): void
    {
        $previous = decrypt($previous);
        foreach (['ai.enabled', 'ai.model', 'ai.auto_actions', 'ai.budget', 'ai.key'] as $key) {
            Setting::put($key, $previous[$key] ?? null);
        }
        $this->mount($settings);
        $this->resetValidation();
        $this->dispatch('toast', message: __('Değişiklik geri alındı.'));
    }

    public function removeKey(AiSettings $settings): void
    {
        $previous = $this->current($settings);
        $settings->setApiKey(null);
        $this->check = null;
        $this->dispatch('toast', message: __('API anahtarı silindi.'), source: $this->getId(),
            undo: ['method' => 'undoSave', 'args' => [encrypt($previous)]]);
    }

    /**
     * Checks the typed key, or the saved one, with the chosen model.
     */
    public function test(AiSettings $settings, ClaudeClient $claude): void
    {
        $key = trim($this->key) ?: $settings->apiKey();
        if ($key === null) {
            $this->check = [false, __('Önce bir API anahtarı girin.')];

            return;
        }
        try {
            $claude->check($key, Models::exists($this->model) ? $this->model : Models::DEFAULT);
            $this->check = [true, __('Bağlantı çalışıyor: :model kullanılabilir.', ['model' => Models::label($this->model)])];
        } catch (ClaudeException $e) {
            $this->check = [false, $e->getMessage()];
        }
    }

    public function render(AiSettings $settings): View
    {
        $month = fn ($from, $to) => AiUsage::where('created_at', '>=', $from)->where('created_at', '<', $to)
            ->select(DB::raw('count(*) as requests'), DB::raw('coalesce(sum(input_tokens + cache_read_tokens + cache_write_tokens), 0) as input'),
                DB::raw('coalesce(sum(output_tokens), 0) as output'), DB::raw('coalesce(sum(cost), 0) as cost'))
            ->first();

        return view('livewire.ai.settings', [
            'masked' => $settings->maskedKey(),
            'thisMonth' => $month(now()->startOfMonth(), now()->addDay()),
            'lastMonth' => $month(now()->startOfMonth()->subMonth(), now()->startOfMonth()),
            'savedBudget' => $settings->budget(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function current(AiSettings $settings): array
    {
        return [
            'ai.enabled' => Setting::get('ai.enabled'),
            'ai.model' => Setting::get('ai.model'),
            'ai.auto_actions' => Setting::get('ai.auto_actions'),
            'ai.budget' => Setting::get('ai.budget'),
            'ai.key' => Setting::get('ai.key'),
        ];
    }
}
