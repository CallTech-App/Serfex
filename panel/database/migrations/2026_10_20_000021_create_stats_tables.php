<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * İstatistikler: what each site served (hourly for a month, daily for
     * two years), the pages, referrers, countries and bots behind it, each
     * mail domain's messages, the server's resources by hour, and the
     * usage warnings customers were sent.
     */
    public function up(): void
    {
        $counts = function (Blueprint $table) {
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            // Visitors first seen that day, in this hour (daily rows: the
            // day's distinct visitors).
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unsignedBigInteger('pages')->default(0);
            $table->unsignedBigInteger('bot_requests')->default(0);
            $table->unsignedBigInteger('bot_bytes')->default(0);
            $table->unsignedBigInteger('s2xx')->default(0);
            $table->unsignedBigInteger('s3xx')->default(0);
            $table->unsignedBigInteger('s4xx')->default(0);
            $table->unsignedBigInteger('s5xx')->default(0);
        };

        Schema::create('site_stats_hourly', function (Blueprint $table) use ($counts) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->timestamp('hour');
            $counts($table);
            $table->unique(['site_id', 'hour']);
            $table->index('hour');
        });

        // Days in the panel's time zone.
        Schema::create('site_stats_daily', function (Blueprint $table) use ($counts) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $counts($table);
            $table->unique(['site_id', 'day']);
            $table->index('day');
        });

        // The top pages, referrers, countries and bots of a site's day; ""
        // adds up the rest.
        Schema::create('site_stats_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->string('kind', 10);
            $table->string('value', 255);
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('bytes')->default(0);
            $table->unsignedBigInteger('visitors')->default(0);
            $table->unique(['site_id', 'day', 'kind', 'value']);
            $table->index(['site_id', 'kind', 'day']);
        });

        Schema::create('mail_stats_daily', function (Blueprint $table) {
            $table->id();
            $table->string('domain', 253);
            $table->date('day');
            $table->unsignedBigInteger('sent')->default(0);
            $table->unsignedBigInteger('received')->default(0);
            $table->unsignedBigInteger('rejected')->default(0);
            $table->unsignedBigInteger('bounced')->default(0);
            $table->unique(['domain', 'day']);
        });

        // The whole server, one row per hour, kept 400 days.
        Schema::create('server_stats', function (Blueprint $table) {
            $table->id();
            $table->timestamp('hour')->unique();
            $table->float('cpu')->default(0);
            $table->float('cpu_max')->default(0);
            $table->unsignedBigInteger('memory')->default(0);
            $table->unsignedBigInteger('memory_max')->default(0);
            $table->unsignedBigInteger('memory_total')->default(0);
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            // Bytes received and sent during the hour.
            $table->unsignedBigInteger('net_rx')->default(0);
            $table->unsignedBigInteger('net_tx')->default(0);
            $table->float('load')->default(0);
        });

        // The server's minute samples carry what the hourly rows need.
        Schema::table('monitor_samples', function (Blueprint $table) {
            $table->unsignedBigInteger('memory_total')->nullable();
            $table->float('net_rx')->nullable();
            $table->float('net_tx')->nullable();
            $table->unsignedBigInteger('disk_used')->nullable();
            $table->unsignedBigInteger('disk_total')->nullable();
            $table->float('load')->nullable();
        });

        // What the site's folder takes, measured with its account's home.
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('disk_bytes')->nullable();
        });

        // Warnings a customer was mailed about their disk or traffic: one
        // per level, sent again only after usage fell below it.
        Schema::create('usage_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 16);
            $table->string('level', 8);
            $table->timestamp('sent_at');
            $table->unique(['customer_id', 'kind', 'level']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usage_notices');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('disk_bytes'));
        Schema::table('monitor_samples', fn (Blueprint $table) => $table->dropColumn(['memory_total', 'net_rx', 'net_tx', 'disk_used', 'disk_total', 'load']));
        Schema::dropIfExists('server_stats');
        Schema::dropIfExists('mail_stats_daily');
        Schema::dropIfExists('site_stats_items');
        Schema::dropIfExists('site_stats_daily');
        Schema::dropIfExists('site_stats_hourly');
    }
};
