<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DNSSEC per zone: whether it is signed, its private key (encrypted
     * with the application key; kept after signing is turned off so the
     * registrar's DS record stays valid when it is turned on again) and
     * the DS records PowerDNS reported for it.
     */
    public function up(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->boolean('dnssec')->default(false)->after('minimum');
            $table->text('dnssec_key')->nullable()->after('dnssec');
            $table->json('dnssec_info')->nullable()->after('dnssec_key');
        });
    }

    public function down(): void
    {
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropColumn(['dnssec', 'dnssec_key', 'dnssec_info']);
        });
    }
};
