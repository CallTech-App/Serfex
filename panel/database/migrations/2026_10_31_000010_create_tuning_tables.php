<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Service optimization: hourly measurements of the database server and
 * the changes made from its suggestions (applied, scheduled for the
 * maintenance window, watched, rolled back, undone or dismissed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tuning_samples', function (Blueprint $table) {
            $table->id();
            $table->string('unit', 64);
            $table->jsonb('data');
            $table->timestamp('created_at')->index();
            $table->index(['unit', 'created_at']);
        });

        Schema::create('tuning_changes', function (Blueprint $table) {
            $table->id();
            $table->string('unit', 64);
            $table->string('key', 40);
            $table->string('title');
            $table->jsonb('from_values');
            $table->jsonb('to_values');
            $table->string('from_label', 64);
            $table->string('to_label', 64);
            // scheduled, watching, applied, rolled_back, undone, failed,
            // cancelled or dismissed.
            $table->string('status', 16)->index();
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('watch_until')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->jsonb('baseline')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->index(['unit', 'key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tuning_changes');
        Schema::dropIfExists('tuning_samples');
    }
};
