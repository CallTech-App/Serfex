<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Application templates: an administrator's own Laravel application,
     * described once (where its code or release package comes from, its
     * database, .env and setup commands) and installed on any site. They
     * are data of this panel only. Applications installed from one
     * remember it and the version they run.
     */
    public function up(): void
    {
        Schema::create('app_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('description', 300)->nullable();
            // git: Serfex deploys and updates it; package: a release zip
            // installed once, the application updates itself.
            $table->string('source', 10)->default('git');
            $table->string('provider', 20)->default('github');
            $table->foreignId('git_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('repository', 300)->nullable();
            $table->string('remote_repository', 300)->nullable();
            $table->boolean('private')->default(false);
            $table->string('ref_type', 10)->default('branch');
            $table->string('ref', 200)->nullable();
            $table->string('package_asset', 200)->nullable();
            $table->string('package_url', 2048)->nullable();
            $table->string('latest_version', 100)->nullable();
            $table->string('latest_commit', 64)->nullable();
            $table->json('latest_package')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->string('check_error', 500)->nullable();
            $table->string('php_version', 10)->nullable();
            $table->string('database', 20)->default('mysql');
            $table->boolean('frontend')->default(true);
            $table->string('package_manager', 10)->nullable();
            $table->boolean('worker')->default(true);
            $table->boolean('scheduler')->default(true);
            $table->text('env_template')->nullable();
            $table->json('fields')->nullable();
            $table->text('setup_commands')->nullable();
            $table->boolean('create_admin')->default(false);
            $table->string('admin_command', 1000)->nullable();
            $table->boolean('migrate')->default(true);
            $table->json('hooks')->nullable();
            $table->boolean('update_maintenance')->default(true);
            $table->boolean('update_backup')->default(true);
            $table->boolean('update_rollback')->default(true);
            $table->boolean('customers_can_install')->default(false);
            $table->string('health_path', 200)->default('/up');
            $table->string('version_file', 200)->default('VERSION');
            $table->timestamps();
        });

        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->foreignId('app_template_id')->nullable()->constrained()->nullOnDelete();
            $table->string('template_version', 100)->nullable();
            // The answers, files and first administrator of an install
            // until its setup has run (encrypted, then removed).
            $table->text('template_setup')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('app_template_id');
            $table->dropColumn(['template_version', 'template_setup']);
        });
        Schema::dropIfExists('app_templates');
    }
};
