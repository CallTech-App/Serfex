<?php

namespace App\Services\Ai;

use App\Models\AiUsage;
use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Serfex AI's server-wide settings. The Claude API key is stored
 * encrypted with the panel's application key.
 */
class AiSettings
{
    public function apiKey(): ?string
    {
        $stored = Setting::get('ai.key');
        if (! is_string($stored) || $stored === '') {
            return null;
        }
        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return null;
        }
    }

    public function setApiKey(?string $key): void
    {
        Setting::put('ai.key', $key === null || $key === '' ? null : Crypt::encryptString($key));
    }

    /**
     * "sk-ant-…a1b2", for showing which key is saved.
     */
    public function maskedKey(): ?string
    {
        $key = $this->apiKey();

        return $key === null ? null : mb_substr($key, 0, 7).'…'.mb_substr($key, -4);
    }

    public function enabled(): bool
    {
        return (bool) Setting::get('ai.enabled', true);
    }

    /**
     * True when the assistant can answer: turned on and a key is saved.
     */
    public function ready(): bool
    {
        return $this->enabled() && $this->apiKey() !== null;
    }

    public function model(): string
    {
        $model = (string) Setting::get('ai.model', Models::DEFAULT);

        return Models::exists($model) ? $model : Models::DEFAULT;
    }

    /**
     * Whether the assistant may block attacking addresses and limit sites
     * by itself (time-boxed). Otherwise it proposes those too.
     */
    public function autoActions(): bool
    {
        return (bool) Setting::get('ai.auto_actions', true);
    }

    /**
     * Monthly spending cap in dollars; null for none.
     */
    public function budget(): ?float
    {
        $budget = Setting::get('ai.budget');

        return is_numeric($budget) && $budget > 0 ? (float) $budget : null;
    }

    /**
     * Estimated spend since the start of this month, in dollars.
     */
    public function spentThisMonth(): float
    {
        return (float) AiUsage::where('created_at', '>=', now()->startOfMonth())->sum('cost');
    }

    public function overBudget(): bool
    {
        $budget = $this->budget();

        return $budget !== null && $this->spentThisMonth() >= $budget;
    }
}
