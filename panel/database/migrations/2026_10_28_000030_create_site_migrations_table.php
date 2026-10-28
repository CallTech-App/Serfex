<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Accounts moved from another panel (cPanel): the archive read, what
     * it holds, what the administrator chose to bring, and how each site
     * came through. New passwords made on the way are kept encrypted for
     * the report.
     */
    public function up(): void
    {
        Schema::create('site_migrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('source', 16)->default('cpanel');
            // An archive in the import folder, or one uploaded and kept by the agent.
            $table->string('file')->nullable();
            $table->string('upload', 64)->nullable();
            $table->string('name');
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status', 16)->default('ready');
            $table->json('plan');
            $table->json('options')->nullable();
            $table->json('result')->nullable();
            $table->text('secrets')->nullable();
            $table->string('run', 64)->nullable();
            $table->string('backup_key', 64)->nullable();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_migrations');
    }
};
