<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Scheduled tasks of a Laravel application turned off in Serfex, by
     * the key the agent gives each task. The scheduler skips them.
     */
    public function up(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->json('skipped_tasks')->default('[]');
        });
    }

    public function down(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->dropColumn('skipped_tasks');
        });
    }
};
