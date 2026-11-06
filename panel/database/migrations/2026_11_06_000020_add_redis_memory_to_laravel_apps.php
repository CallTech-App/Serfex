<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The memory limit of a Laravel application's own Redis (null: Serfex's
 * default, 128 MB), set by the optimization suggestions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->unsignedInteger('redis_memory_mb')->nullable()->after('redis_password');
        });
    }

    public function down(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->dropColumn('redis_memory_mb');
        });
    }
};
