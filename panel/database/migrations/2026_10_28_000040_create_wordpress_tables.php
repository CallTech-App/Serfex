<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * WordPress araç seti: the WordPress installation a site has (one per
     * site), what the agent last read from it, and its installs, updates
     * and returns to a restore point.
     */
    public function up(): void
    {
        Schema::create('wordpress_installs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained()->cascadeOnDelete();
            // The WordPress folder, relative to the site directory.
            $table->string('path', 200);
            // installing, active or failed.
            $table->string('status', 16)->default('active');
            $table->foreignId('database_id')->nullable()->constrained()->nullOnDelete();
            $table->string('version', 32)->nullable();
            $table->string('core_update', 32)->nullable();
            // Updates offered: WordPress, plugins and themes together.
            $table->unsignedInteger('update_count')->default(0);
            $table->boolean('maintenance')->default(false);
            $table->text('problem')->nullable();
            // The agent's last wordpress.status answer.
            $table->json('state')->nullable();
            $table->timestamp('checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('wordpress_operations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('wordpress_install_id')->constrained()->cascadeOnDelete();
            // install, update or restore.
            $table->string('kind', 16);
            $table->string('run', 64);
            $table->string('status', 16)->default('queued');
            // What was asked: core, plugins, themes; a restore's operation id.
            $table->json('request')->nullable();
            // What changed: kind, name, from, to.
            $table->json('changes')->nullable();
            // The restore point an update took (its run).
            $table->string('point', 64)->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('triggered_by', 120)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wordpress_operations');
        Schema::dropIfExists('wordpress_installs');
    }
};
