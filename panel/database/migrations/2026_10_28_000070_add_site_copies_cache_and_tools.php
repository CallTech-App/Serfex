<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Siteyi kopyala: a site can be the staging copy of another (its
     * databases the copies of the other's), with the copies, refreshes
     * and publishes it went through. Önbellek ve hız: a site's page cache,
     * browser cache and compression. Composer ve Node.js: the Node.js line
     * a site's commands use, and the commands run on it.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->foreignId('copy_of_id')->nullable()->constrained('sites')->nullOnDelete();
            // Asks search engines to stay out (X-Robots-Tag: noindex).
            $table->boolean('noindex')->default(false);
            $table->json('cache')->nullable();
            $table->string('node_version', 4)->nullable();
        });

        Schema::table('databases', function (Blueprint $table) {
            $table->foreignId('copy_of_id')->nullable()->constrained('databases')->nullOnDelete();
        });

        Schema::create('site_copies', function (Blueprint $table) {
            $table->id();
            // The live site.
            $table->foreignId('source_id')->constrained('sites')->cascadeOnDelete();
            // The staging copy, whichever way the files go; null once it
            // is deleted, so a copy that could not be made still says why.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            // create, refresh (source → copy) or publish (copy → source).
            $table->string('kind', 16);
            // files and/or databases.
            $table->json('parts');
            $table->string('run', 64);
            // queued, running, succeeded, failed or undone.
            $table->string('status', 16)->default('queued');
            // What the agent could not do by itself (codes and subjects).
            $table->json('notes')->nullable();
            $table->text('error')->nullable();
            // A publish backs the live site up first; Undo restores it.
            $table->foreignId('backup_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('restore_id')->nullable()->constrained('backup_restores')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('site_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // composer, npm, pnpm, yarn or bun.
            $table->string('tool', 16);
            // The folder, relative to the site directory ("" is the site's own).
            $table->string('path', 200)->default('');
            $table->text('command');
            $table->string('run', 64);
            $table->string('status', 16)->default('queued');
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_commands');
        Schema::dropIfExists('site_copies');
        Schema::table('databases', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copy_of_id');
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copy_of_id');
            $table->dropColumn(['noindex', 'cache', 'node_version']);
        });
    }
};
