<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cloudflare API tokens the administrator connected. Zones whose DNS
        // is at Cloudflare are kept in the panel as usual and written there
        // through one of them.
        Schema::create('cloudflare_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('token');
            $table->string('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->unsignedInteger('zones_count')->default(0);
            // What the last check found the token can do: key => true|false.
            $table->json('checks')->nullable();
            // ok | invalid
            $table->string('status')->default('ok');
            $table->text('last_error')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::table('dns_zones', function (Blueprint $table) {
            $table->foreignId('cloudflare_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('cloudflare_zone_id')->nullable();
            // status, name_servers, original_name_servers, ssl, dnssec, ...
            $table->json('cloudflare_info')->nullable();
            // Record ids written or seen at the last sync: one missing from
            // the panel since was deleted here, one not listed is new there.
            $table->json('cloudflare_known_ids')->nullable();
            $table->timestamp('cloudflare_synced_at')->nullable();
        });

        Schema::table('dns_records', function (Blueprint $table) {
            // Through Cloudflare's proxy (orange cloud); null: not decided
            // yet, Serfex picks when it first writes the record.
            $table->boolean('proxied')->nullable();
            $table->string('cloudflare_id')->nullable();
            // What the record was at the last sync, to tell which side
            // changed it since.
            $table->string('cloudflare_hash')->nullable();
            $table->timestamp('cloudflare_modified_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('dns_records', function (Blueprint $table) {
            $table->dropColumn(['proxied', 'cloudflare_id', 'cloudflare_hash', 'cloudflare_modified_at']);
        });
        Schema::table('dns_zones', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cloudflare_account_id');
            $table->dropColumn(['cloudflare_zone_id', 'cloudflare_info', 'cloudflare_known_ids', 'cloudflare_synced_at']);
        });
        Schema::dropIfExists('cloudflare_accounts');
    }
};
