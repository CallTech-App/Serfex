<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Serfex's own updates and rollbacks (Araçlar ve Ayarlar →
     * Güncellemeler). The agent performs them; this is their history.
     */
    public function up(): void
    {
        Schema::create('panel_updates', function (Blueprint $table) {
            $table->id();
            // upgrade or rollback.
            $table->string('kind', 16);
            $table->string('from_version', 64);
            $table->string('to_version', 64);
            // queued, backing_up, running, succeeded, failed or rolled_back
            // (failed, and the previous release runs again).
            $table->string('status', 16)->index();
            // The agent's log of the run (update.run.read).
            $table->string('run', 64)->unique();
            $table->text('error')->nullable();
            // The panel backup taken first (Yedekler).
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('panel_updates');
    }
};
