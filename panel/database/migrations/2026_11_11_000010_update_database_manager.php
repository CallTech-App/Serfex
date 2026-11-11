<?php

use App\Jobs\UpdateDatabaseManager;
use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * From 0.1.72 phpMyAdmin (5.2.3) and Adminer (6.3.0) come from their
     * makers' releases instead of Ubuntu's packages (phpMyAdmin 5.2.1, with
     * two XSS issues fixed in 5.2.2, and Adminer 4.8.1). Servers set up
     * before move to them once, in the background, after the update has
     * finished and passed its health check.
     */
    public function up(): void
    {
        // The database servers the panel last saw, read without resolving
        // DatabaseServers, which would keep its agent client and cache for
        // the rest of the process.
        if (Setting::get('databases.products', []) !== []) {
            UpdateDatabaseManager::dispatch()->delay(now()->addMinutes(3));
        }
    }

    public function down(): void {}
};
