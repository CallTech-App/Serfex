<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * DNS zones served by this server and their records. The panel is the
     * source of truth; servik-agent makes PowerDNS serve exactly these.
     */
    public function up(): void
    {
        Schema::create('dns_zones', function (Blueprint $table) {
            $table->id();
            // The site the zone was created for; a zone outlives its site
            // when the user chooses to keep it.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 253)->unique();
            $table->string('status', 16)->index();
            $table->string('hostmaster')->nullable();
            $table->unsignedInteger('default_ttl');
            $table->unsignedInteger('refresh');
            $table->unsignedInteger('retry');
            $table->unsignedInteger('expire');
            $table->unsignedInteger('minimum');
            $table->unsignedBigInteger('serial')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('dns_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dns_zone_id')->constrained()->cascadeOnDelete();
            // Relative to the zone; "@" is the apex.
            $table->string('name', 253);
            $table->string('type', 10);
            // Presentation form without the priority, e.g. "mail.example.com."
            // for MX, "5 5060 sip.example.com." for SRV, unquoted text for TXT.
            $table->text('content');
            $table->unsignedSmallInteger('priority')->nullable();
            $table->unsignedInteger('ttl');
            $table->timestamps();

            $table->index(['dns_zone_id', 'name', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_records');
        Schema::dropIfExists('dns_zones');
    }
};
