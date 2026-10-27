<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A failed request is retried once a day; one that never reached
     * Let's Encrypt (a lookup or connection failure) is retried at
     * retry_at instead, within the hour.
     */
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->timestamp('retry_at')->nullable()->after('attempted_at');
        });

        // Requests that failed because this server could not look up
        // Let's Encrypt while PowerDNS was being set up: retry them now.
        DB::table('certificates')
            ->where('status', 'failed')
            ->where('last_error', 'like', '%lookup acme-v02.api.letsencrypt.org%')
            ->update(['retry_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropColumn('retry_at');
        });
    }
};
