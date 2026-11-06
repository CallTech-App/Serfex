<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // serfex:uptime opens the site every minute, as a visitor would.
            $table->boolean('uptime_enabled')->default(true);
            // The path checked; null is the Laravel health path or "/".
            $table->string('uptime_path', 200)->nullable();
            // up, down, or null until the site answered once.
            $table->string('uptime_state', 10)->nullable();
            $table->unsignedSmallInteger('uptime_failures')->default(0);
            $table->timestamp('uptime_checked_at')->nullable();
            $table->timestamp('uptime_since')->nullable();
            $table->unsignedInteger('uptime_ms')->nullable();
            $table->string('uptime_error', 300)->nullable();
            $table->string('uptime_cause', 300)->nullable();
        });

        // Checks in 15-minute buckets, kept 90 days.
        Schema::create('site_uptime', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('bucket_at');
            $table->unsignedSmallInteger('checks')->default(0);
            $table->unsignedSmallInteger('failures')->default(0);
            // Sum of the response times of the successful checks.
            $table->unsignedBigInteger('ms_total')->default(0);
            $table->unique(['site_id', 'bucket_at']);
        });

        Schema::create('site_outages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->string('error', 300);
            $table->string('cause', 300)->nullable();
            $table->timestamps();
            $table->index(['site_id', 'started_at']);
        });

        Schema::table('cron_jobs', function (Blueprint $table) {
            // Runs in a row that failed, up to the last one.
            $table->unsignedSmallInteger('failed_runs')->default(0);
        });

        Schema::table('laravel_apps', function (Blueprint $table) {
            // serfex:laravel-watch: workers' and processes' restarts over
            // the last minutes, and the scheduler's failing tasks.
            $table->json('watch')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('laravel_apps', fn (Blueprint $table) => $table->dropColumn('watch'));
        Schema::table('cron_jobs', fn (Blueprint $table) => $table->dropColumn('failed_runs'));
        Schema::dropIfExists('site_outages');
        Schema::dropIfExists('site_uptime');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['uptime_enabled', 'uptime_path', 'uptime_state', 'uptime_failures', 'uptime_checked_at', 'uptime_since', 'uptime_ms', 'uptime_error', 'uptime_cause']);
        });
    }
};
