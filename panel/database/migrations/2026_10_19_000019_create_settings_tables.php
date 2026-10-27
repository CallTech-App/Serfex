<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Araçlar ve Ayarlar: administrator roles, the log of every action
     * taken through the panel, API keys, the notifications sent about
     * problems, and each site's scheduled tasks with a package limit.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Administrators only: full, support or readonly.
            $table->string('role', 16)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            // A removed administrator can no longer sign in; the account
            // is deleted once the undo window is over.
            $table->timestamp('removed_at')->nullable();
        });
        DB::table('users')->where('is_admin', true)->update(['role' => 'full']);

        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Who it was when it happened: a name, "Serfex AI" or "API: <key>".
            $table->string('actor', 120);
            // panel, api, ai or system.
            $table->string('via', 16)->default('panel');
            // The customer whose hosting the action was on, when a customer did it.
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->string('section', 120)->nullable();
            $table->text('message');
            // Where it happened and how to undo it there: the page, the
            // Livewire component and {method, args}.
            $table->string('url', 500)->nullable();
            $table->string('component', 160)->nullable();
            $table->json('undo')->nullable();
            $table->timestamp('undone_at')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->index();
        });

        Schema::create('api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // The first characters, shown in lists (svk_live_a91c…).
            $table->string('prefix', 24);
            $table->string('token_hash', 64)->unique();
            // read or write.
            $table->string('scope', 16)->default('read');
            $table->json('allowed_ips')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('notification_events', function (Blueprint $table) {
            $table->id();
            // certificate, backup, update, disk or service.
            $table->string('kind', 16);
            // What the problem is about, stable while it lasts.
            $table->string('key', 191)->unique();
            $table->string('title', 255);
            $table->text('detail')->nullable();
            $table->string('url', 500)->nullable();
            // open or resolved.
            $table->string('state', 16)->default('open');
            // Channels that got the opening and the resolution: {email: true, webhook: false}.
            $table->json('sent')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cron_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('schedule', 100);
            $table->text('command');
            $table->string('note', 100)->nullable();
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('timeout')->default(3600);
            // From the last run the panel read: ok, failed, timeout or skipped.
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_status', 16)->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_cron_jobs')->nullable();
        });
        foreach (['Başlangıç' => 2, 'Profesyonel' => 10, 'Laravel Pro' => 20] as $name => $max) {
            DB::table('packages')->where('name', $name)->whereNull('max_cron_jobs')->update(['max_cron_jobs' => $max]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('max_cron_jobs'));
        Schema::dropIfExists('cron_jobs');
        Schema::dropIfExists('notification_events');
        Schema::dropIfExists('api_keys');
        Schema::dropIfExists('audit_entries');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['role', 'last_login_at', 'last_login_ip', 'removed_at']));
    }
};
