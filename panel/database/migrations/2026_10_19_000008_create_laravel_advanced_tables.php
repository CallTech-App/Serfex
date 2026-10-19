<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Laravel toolkit's second half: preview environments, long-running
     * processes, per-application backups, application members and API
     * tokens, and maintenance mode served by Nginx.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Administrators see the whole panel; other accounts only the
            // Laravel applications they were added to.
            $table->boolean('is_admin')->default(true)->after('password');
            // Invitation link, stored hashed; cleared once a password is set.
            $table->string('invite_token', 64)->nullable()->unique()->after('is_admin');
            $table->timestamp('invited_at')->nullable()->after('invite_token');
        });

        Schema::table('laravel_apps', function (Blueprint $table) {
            // A preview environment points at the application it previews.
            $table->foreignId('preview_of_id')->nullable()->after('site_id')->constrained('laravel_apps')->nullOnDelete();
            // Previews made by pull request webhooks.
            $table->boolean('previews_enabled')->default(false);
            // sqlite: a new SQLite database per preview; parent: the
            // application's own database settings.
            $table->string('preview_database', 16)->default('sqlite');
            $table->boolean('preview_seed')->default(true);
            // KEY=VALUE lines written on top of the copied .env.
            $table->text('preview_env')->nullable();
            $table->unsignedSmallInteger('preview_limit')->default(5);

            // servik (Servik's page in Nginx), custom (own HTML in Nginx) or
            // app (php artisan down, the application's 503 page).
            $table->string('maintenance_mode', 16)->default('servik');
            $table->boolean('maintenance_on')->default(false);
            $table->text('maintenance_allow')->nullable();
            $table->text('maintenance_html')->nullable();

            // off, daily or weekly.
            $table->string('backup_schedule', 8)->default('off');
            $table->string('backup_time', 5)->default('03:00');
            $table->unsignedSmallInteger('backup_keep')->default(7);
            $table->boolean('backup_before_deploy')->default(false);
        });

        Schema::create('laravel_previews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            // The preview's own site and application.
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('preview_app_id')->nullable()->constrained('laravel_apps')->nullOnDelete();
            // pr-212 or a branch slug; the site is <name>.<domain>.
            $table->string('name', 40);
            $table->string('branch');
            $table->unsignedInteger('pr_number')->nullable();
            $table->string('pr_title')->nullable();
            $table->string('pr_url')->nullable();
            // manual or webhook
            $table->string('source', 16)->default('manual');
            // creating, active, failed or deleting
            $table->string('status', 16)->index();
            $table->text('error')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamps();
            $table->unique(['laravel_app_id', 'name']);
        });

        Schema::create('laravel_processes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            // horizon, octane, reverb or custom
            $table->string('kind', 16);
            $table->boolean('enabled')->default(true);
            // Octane and Reverb listen on 127.0.0.1:<port>; Nginx proxies.
            $table->unsignedInteger('port')->nullable()->unique();
            $table->string('server', 16)->nullable();
            $table->unsignedSmallInteger('workers')->default(0);
            $table->unsignedInteger('max_requests')->default(500);
            $table->text('command')->nullable();
            $table->timestamps();
            $table->unique(['laravel_app_id', 'name']);
        });

        Schema::create('laravel_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            // The backup's name on the server.
            $table->string('key', 64)->unique();
            // manual, scheduled, deploy (before a deploy) or restore (before
            // a restore)
            $table->string('kind', 16);
            // running, succeeded, failed or deleted (gone in a few minutes)
            $table->string('status', 16)->index();
            $table->jsonb('parts');
            $table->jsonb('part_sizes')->default('{}');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('release', 64)->nullable();
            $table->string('database_driver', 16)->nullable();
            $table->string('run', 64);
            $table->text('error')->nullable();
            $table->string('note')->nullable();
            $table->string('user_name')->nullable();
            $table->timestamp('finished_at')->nullable();
            // The last restore from this backup: its log and outcome.
            $table->string('restore_run', 64)->nullable();
            $table->string('restore_status', 16)->nullable();
            $table->text('restore_error')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
        });

        Schema::create('laravel_app_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // owner, developer or viewer
            $table->string('role', 16);
            $table->timestamps();
            $table->unique(['laravel_app_id', 'user_id']);
        });

        Schema::create('laravel_api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            $table->string('name', 64);
            // SHA-256 of the token; the token itself is shown once.
            $table->string('token_hash', 64)->unique();
            $table->string('last_four', 4);
            $table->string('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->string('last_used_ip', 45)->nullable();
            $table->string('last_used_agent')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_api_tokens');
        Schema::dropIfExists('laravel_app_members');
        Schema::dropIfExists('laravel_backups');
        Schema::dropIfExists('laravel_processes');
        Schema::dropIfExists('laravel_previews');
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preview_of_id');
            $table->dropColumn(['previews_enabled', 'preview_database', 'preview_seed', 'preview_env', 'preview_limit',
                'maintenance_mode', 'maintenance_on', 'maintenance_allow', 'maintenance_html',
                'backup_schedule', 'backup_time', 'backup_keep', 'backup_before_deploy']);
        });
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['is_admin', 'invite_token', 'invited_at']));
    }
};
