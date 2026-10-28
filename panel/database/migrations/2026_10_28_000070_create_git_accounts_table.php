<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * GitHub, GitLab and Bitbucket accounts panel users connected with
     * OAuth, to pick repositories from a list. Repositories picked that
     * way remember the account and the deploy key and webhook Serfex added
     * to them, so disconnecting removes both again.
     */
    public function up(): void
    {
        Schema::create('git_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20);
            $table->string('remote_id', 100);
            $table->string('username', 200);
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'provider']);
        });

        foreach (['git_repositories', 'laravel_apps'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->foreignId('git_account_id')->nullable()->constrained()->nullOnDelete();
                $table->string('remote_repository', 300)->nullable();
                $table->string('remote_key_id', 100)->nullable();
                $table->string('remote_hook_id', 100)->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['git_repositories', 'laravel_apps'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->dropConstrainedForeignId('git_account_id');
                $table->dropColumn(['remote_repository', 'remote_key_id', 'remote_hook_id']);
            });
        }
        Schema::dropIfExists('git_accounts');
    }
};
