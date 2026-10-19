<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Yedekler: remote storage backups are sent to, backup sets (a
     * whole-server backup or one site's), the archives in each set (one per
     * site, plus the panel's own), restores, and what a package allows.
     */
    public function up(): void
    {
        Schema::create('backup_targets', function (Blueprint $table) {
            $table->id();
            $table->string('name', 64);
            // s3 or sftp; the local disk is not a row.
            $table->string('type', 8);
            // Address and credentials, encrypted with the panel's key.
            $table->text('config');
            // untested, ok or failed (the last test's result).
            $table->string('status', 16)->default('untested');
            $table->text('last_error')->nullable();
            $table->timestamp('tested_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            // The set's name on the server and on the target.
            $table->string('key', 64)->unique();
            // server (every site and the panel) or site.
            $table->string('scope', 8);
            // full or incremental.
            $table->string('type', 16)->default('full');
            // scheduled, manual, safety (before a restore), import.
            $table->string('trigger', 16);
            // The previous backup of an incremental's chain.
            $table->foreignId('base_id')->nullable()->constrained('backups')->nullOnDelete();
            $table->foreignId('target_id')->nullable()->constrained('backup_targets')->nullOnDelete();
            // Where the archives are: on this server, on the target.
            $table->boolean('local')->default(true);
            $table->boolean('remote')->default(false);
            $table->boolean('encrypted')->default(false);
            // The passphrase the remote copy was encrypted with (itself
            // encrypted with the panel's key), so a later change does not
            // strand older backups.
            $table->text('passphrase')->nullable();
            // Folder on the target the set is under.
            $table->string('remote_prefix', 200)->nullable();
            // queued, running, succeeded, partial (some archives failed),
            // failed or deleted (hidden while Undo lasts).
            $table->string('status', 16)->index();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedInteger('seconds')->nullable();
            $table->string('run', 64)->unique();
            $table->text('error')->nullable();
            // Where the set came from, for imported ones.
            $table->string('source_host')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // A customer's own backup (counted against their package).
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            // A remote-only set fetched for a download or restore.
            $table->timestamp('cached_at')->nullable();
            $table->timestamps();
            $table->index(['scope', 'trigger', 'created_at']);
        });

        Schema::create('backup_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_id')->constrained()->cascadeOnDelete();
            // The site's domain, or "panel".
            $table->string('name');
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('size')->default(0);
            // Size of each part (files, home, databases, mail, ...).
            $table->json('parts')->default('{}');
            $table->json('incremental')->default('[]');
            $table->json('skipped')->default('{}');
            $table->json('warnings')->default('[]');
            // The panel's description of the site when it was backed up
            // (account, databases, mailboxes, DNS records, certificates).
            $table->json('meta')->nullable();
            // succeeded or failed.
            $table->string('status', 16)->default('succeeded');
            $table->text('error')->nullable();
            $table->timestamps();
            $table->unique(['backup_id', 'name']);
            $table->index('name');
        });

        Schema::create('backup_restores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('backup_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->string('domain');
            $table->json('parts');
            // The backup of the current state taken first, for Undo.
            $table->foreignId('safety_backup_id')->nullable()->constrained('backups')->nullOnDelete();
            // The site was gone and was created again from the backup.
            $table->boolean('recreated')->default(false);
            // queued, running, succeeded or failed.
            $table->string('status', 16)->index();
            $table->string('run', 64)->unique();
            $table->json('result')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            // Backups the customer takes themselves and keeps at once.
            $table->unsignedInteger('max_backups')->nullable();
            // Off: the customer's sites are left out of scheduled backups
            // and they have no Yedekler page.
            $table->boolean('allow_backups')->default(true);
        });
        foreach (['Başlangıç' => 3, 'Profesyonel' => 5, 'Laravel Pro' => 10] as $name => $max) {
            DB::table('packages')->where('name', $name)->whereNull('max_backups')->update(['max_backups' => $max]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn(['max_backups', 'allow_backups']);
        });
        Schema::dropIfExists('backup_restores');
        Schema::dropIfExists('backup_items');
        Schema::dropIfExists('backups');
        Schema::dropIfExists('backup_targets');
    }
};
