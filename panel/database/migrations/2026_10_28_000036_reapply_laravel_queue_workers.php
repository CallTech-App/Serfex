<?php

use App\Models\LaravelApp;
use App\Services\Laravel\LaravelApps;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Until 0.1.32 every queue worker reached the server with the panel's
     * own database connection name (queue:work pgsql) and crashed on start.
     * Writing the workers again once fixes the servers already set up.
     */
    public function up(): void
    {
        $apps = app(LaravelApps::class);
        LaravelApp::query()->whereHas('workers')->each(function (LaravelApp $app) use ($apps) {
            if (! $app->status->isBusy()) {
                $apps->syncProcesses($app);
            }
        });
    }

    public function down(): void {}
};
