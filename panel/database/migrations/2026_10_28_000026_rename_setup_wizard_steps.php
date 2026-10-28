<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** The setup wizard's step slugs before the panel's addresses were English. */
    private const STEPS = [
        'sunucu' => 'server', 'ad-sunuculari' => 'nameservers', 'bildirimler' => 'notifications',
        'guvenlik' => 'firewall', 'varsayilanlar' => 'defaults', 'yedekler' => 'backups',
    ];

    /**
     * The steps already done or skipped keep their state under the new
     * slugs.
     */
    public function up(): void
    {
        $this->rename(self::STEPS);
    }

    public function down(): void
    {
        $this->rename(array_flip(self::STEPS));
    }

    /**
     * @param  array<string, string>  $names
     */
    private function rename(array $names): void
    {
        $progress = Setting::get('setup.wizard');
        if (! is_array($progress) || ! is_array($progress['steps'] ?? null)) {
            return;
        }
        $steps = [];
        foreach ($progress['steps'] as $step => $state) {
            $steps[$names[$step] ?? $step] = $state;
        }
        $progress['steps'] = $steps;
        Setting::put('setup.wizard', $progress);
    }
};
