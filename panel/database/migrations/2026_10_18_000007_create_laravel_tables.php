<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Laravel applications on sites, their deploys, queue workers, commands
     * run from the panel and earlier versions of their .env.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Directory Nginx serves, relative to the site directory; null is
            // public_html.
            $table->string('web_root')->nullable()->after('document_root');
        });

        Schema::create('laravel_apps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            // Application directory relative to the site directory:
            // laravel/current when Serfex deploys it.
            $table->string('path');
            $table->string('status', 16)->index();
            // create (new project), git, or existing (found on the site).
            $table->string('source', 16);
            $table->string('package')->nullable();
            $table->string('repository')->nullable();
            $table->string('branch')->nullable();
            $table->boolean('frontend')->default(true);
            $table->string('package_manager', 8)->nullable();
            $table->boolean('migrate')->default(true);
            $table->jsonb('hooks')->default('{}');
            $table->string('health_path')->default('/up');
            $table->boolean('auto_rollback')->default(true);
            $table->unsignedSmallInteger('keep_releases')->default(5);
            $table->boolean('auto_deploy')->default(false);
            $table->string('webhook_token', 64)->unique();
            $table->text('deploy_key')->nullable();
            $table->string('laravel_version', 32)->nullable();
            $table->string('php_requirement', 64)->nullable();
            $table->string('current_release', 64)->nullable();
            $table->boolean('scheduler')->default(false);
            $table->string('maintenance_secret', 64)->nullable();
            $table->unsignedInteger('maintenance_retry')->default(60);
            $table->jsonb('saved_commands')->default('{}');
            // Queue workers and the scheduler are applied in the background.
            $table->string('processes_state', 16)->default('ok');
            $table->text('processes_error')->nullable();
            $table->text('last_error')->nullable();
            // Values for the first .env (database password included),
            // encrypted, kept until the install succeeds so it can be retried.
            $table->text('install_env')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('laravel_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // install, deploy or rollback
            $table->string('kind', 16);
            $table->string('status', 16)->index();
            $table->string('ref')->nullable();
            $table->string('commit', 64)->nullable();
            $table->string('message')->nullable();
            $table->string('triggered_by')->nullable();
            $table->string('release', 64)->nullable();
            $table->string('run', 64);
            $table->boolean('migrate')->default(true);
            $table->boolean('frontend')->default(true);
            $table->unsignedSmallInteger('health_status')->nullable();
            $table->unsignedInteger('health_ms')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['laravel_app_id', 'number']);
        });

        Schema::create('laravel_workers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32);
            $table->string('connection', 32)->nullable();
            $table->string('queue', 200)->default('default');
            $table->unsignedSmallInteger('processes')->default(1);
            $table->unsignedInteger('timeout')->default(60);
            $table->unsignedSmallInteger('tries')->default(1);
            $table->unsignedInteger('backoff')->default(0);
            $table->unsignedSmallInteger('sleep')->default(3);
            $table->unsignedInteger('max_jobs')->default(1000);
            $table->unsignedInteger('max_time')->default(3600);
            $table->unsignedSmallInteger('memory')->default(128);
            $table->timestamps();
            $table->unique(['laravel_app_id', 'name']);
        });

        Schema::create('laravel_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tool', 16);
            $table->text('command');
            $table->string('run', 64);
            $table->string('status', 16);
            $table->integer('exit_code')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('laravel_env_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('laravel_app_id')->constrained()->cascadeOnDelete();
            // The .env as it was before a save, encrypted with APP_KEY.
            $table->text('content');
            $table->string('summary');
            $table->string('user_name')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('laravel_env_versions');
        Schema::dropIfExists('laravel_commands');
        Schema::dropIfExists('laravel_workers');
        Schema::dropIfExists('laravel_deployments');
        Schema::dropIfExists('laravel_apps');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('web_root'));
    }
};
