<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alan adını değiştir: each change of a site's domain, with what it was
     * asked to do, what it did and the way back.
     */
    public function up(): void
    {
        Schema::create('site_renames', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('from_domain');
            $table->string('to_domain');
            // The choices made in the dialog: www, old (redirect, alias or
            // none), backup.
            $table->json('options');
            $table->string('run', 64);
            // queued, running, succeeded, failed or undone.
            $table->string('status', 16)->default('queued');
            // What happened, for the person: codes and subjects.
            $table->json('notes')->nullable();
            // What the rename changed in the panel, to undo it.
            $table->json('before')->nullable();
            $table->text('error')->nullable();
            // The rename this one undid, when it is an undo.
            $table->foreignId('undo_of_id')->nullable()->constrained('site_renames')->nullOnDelete();
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_renames');
    }
};
