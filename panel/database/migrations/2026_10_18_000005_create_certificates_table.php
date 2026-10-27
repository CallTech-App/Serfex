<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * TLS certificates. The files live on the server under
     * /etc/serfex/ssl/certs/<name>; a site serves HTTPS with the
     * certificate its certificate_id points at.
     */
    public function up(): void
    {
        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            // "site", "panel" (the panel's own address) or "mail" (the mail
            // server's name).
            $table->string('kind', 8)->default('site');
            $table->string('name', 210)->unique();
            // "letsencrypt" or "custom".
            $table->string('source', 16);
            $table->jsonb('domains')->default('[]');
            $table->string('challenge', 8)->nullable();
            $table->string('issuer')->nullable();
            $table->string('serial', 64)->nullable();
            $table->string('fingerprint', 64)->nullable();
            $table->timestamp('not_before')->nullable();
            $table->timestamp('not_after')->nullable()->index();
            $table->boolean('auto_renew')->default(true);
            $table->string('status', 16)->index();
            $table->text('last_error')->nullable();
            // A failed renewal while the installed certificate still works.
            $table->text('renew_error')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('certificate_id')->nullable()->after('document_root')->constrained()->nullOnDelete();
            $table->boolean('https_redirect')->default(true)->after('certificate_id');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('certificate_id');
            $table->dropColumn('https_redirect');
        });
        Schema::dropIfExists('certificates');
    }
};
