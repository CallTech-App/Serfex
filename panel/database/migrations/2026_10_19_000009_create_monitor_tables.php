<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Live monitoring: blocked addresses, temporary resource limits and a
     * per-minute history of what each site used.
     */
    public function up(): void
    {
        Schema::create('ip_blocks', function (Blueprint $table) {
            $table->id();
            // An address or a prefix, as the agent normalised it.
            $table->string('address', 64)->index();
            $table->string('reason', 200)->nullable();
            // The site that was attacked, when there was one.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // The rule that flagged the address: flood, login, scan, mail, ssh.
            $table->string('kind', 16)->nullable();
            // Who asked: panel (a person), ai (Serfex AI) or api.
            $table->string('source', 16)->default('panel');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // Null blocks until removed.
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('resource_limits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // Percent of one CPU core.
            $table->unsignedSmallInteger('cpu')->nullable();
            $table->unsignedInteger('memory_mb')->nullable();
            $table->unsignedSmallInteger('php_workers')->nullable();
            $table->string('reason', 200)->nullable();
            $table->string('source', 16)->default('panel');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();
        });

        // One row per site per minute (site_id null: the whole server),
        // kept for a day.
        Schema::create('monitor_samples', function (Blueprint $table) {
            $table->id();
            $table->timestamp('recorded_at');
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->float('cpu')->default(0);
            $table->unsignedBigInteger('memory')->default(0);
            $table->float('io')->default(0);
            $table->float('requests')->default(0);
            $table->float('server_errors')->default(0);
            $table->float('php_busy')->nullable();
            $table->unsignedSmallInteger('php_max')->nullable();
            $table->float('db_active')->default(0);
            $table->float('mail_per_hour')->default(0);
            $table->index(['site_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('monitor_samples');
        Schema::dropIfExists('resource_limits');
        Schema::dropIfExists('ip_blocks');
    }
};
