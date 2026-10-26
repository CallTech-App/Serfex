<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Git dağıtımı for sites that are not Laravel applications: the
     * repository a site's folder comes from, and each deploy of it.
     */
    public function up(): void
    {
        Schema::create('git_repositories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('repository', 300);
            $table->string('branch', 200)->default('main');
            // The folder the code goes to, relative to the site directory.
            $table->string('path', 200)->default('public_html');
            // An access token for an https:// repository, encrypted.
            $table->text('token')->nullable();
            // Run as the site's user in the folder after each deploy.
            $table->text('commands')->nullable();
            $table->boolean('auto_deploy')->default(false);
            $table->string('webhook_token', 64)->unique();
            $table->text('deploy_key')->nullable();
            $table->string('live_commit', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('git_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('git_repository_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            // deploy, rollback (an earlier commit) or undo (of the first deploy)
            $table->string('kind', 16);
            $table->string('status', 16)->index();
            $table->string('branch', 200)->nullable();
            $table->string('commit', 64)->nullable();
            $table->string('previous_commit', 64)->nullable();
            $table->string('message')->nullable();
            $table->string('author')->nullable();
            $table->unsignedInteger('changed_files')->nullable();
            // Existing files the first deploy overwrote (kept for its undo).
            $table->unsignedInteger('saved_files')->nullable();
            $table->string('triggered_by')->nullable();
            $table->string('run', 64);
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->unique(['git_repository_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('git_deployments');
        Schema::dropIfExists('git_repositories');
    }
};
