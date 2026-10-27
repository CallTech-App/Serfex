<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mail domains, their mailboxes and forwarders. The panel is the source
     * of truth; serfex-agent makes Postfix, Dovecot and Rspamd follow.
     */
    public function up(): void
    {
        Schema::create('mail_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 253)->unique();
            $table->string('status', 16)->index();
            // Messages Rspamd marks as spam go to Junk.
            $table->boolean('spam_filter')->default(true);
            // Serfex keeps MX, SPF, DKIM and DMARC in the domain's zone.
            $table->boolean('manage_dns')->default(true);
            // The DKIM TXT record the agent returned (name inside the zone
            // of the domain, and content).
            $table->string('dkim_name')->nullable();
            $table->text('dkim_record')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            // Set while mail is turned off but can still be undone.
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();
        });

        Schema::create('mailboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            // The part before the @.
            $table->string('name', 64);
            // Dovecot hash, e.g. {SHA512-CRYPT}$6$...; never the password.
            $table->string('password_hash');
            // 0 means no limit.
            $table->unsignedInteger('quota_mb');
            $table->jsonb('forward_to')->default('[]');
            $table->boolean('keep_copy')->default(true);
            // {enabled, subject, body, until}; kept while turned off.
            $table->jsonb('autoreply')->nullable();
            $table->unsignedBigInteger('usage_bytes')->nullable();
            $table->timestamp('usage_at')->nullable();
            // A removed mailbox keeps its mail until the purge, for Undo.
            $table->softDeletes();
            $table->timestamps();

            $table->unique(['mail_domain_id', 'name']);
        });

        Schema::create('mail_forwarders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mail_domain_id')->constrained()->cascadeOnDelete();
            // The part before the @, or "*" for every address that does not
            // exist (catch-all).
            $table->string('name', 64);
            $table->jsonb('destinations');
            $table->timestamps();

            $table->unique(['mail_domain_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_forwarders');
        Schema::dropIfExists('mailboxes');
        Schema::dropIfExists('mail_domains');
    }
};
