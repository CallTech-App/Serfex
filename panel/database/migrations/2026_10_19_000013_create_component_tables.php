<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Software installed, updated or removed from Yazılım Yönetimi, the web
     * server stack each site runs on, and the Redis instance a Laravel
     * application uses.
     */
    public function up(): void
    {
        Schema::create('component_operations', function (Blueprint $table) {
            $table->id();
            $table->string('component', 32)->index();
            // install, update or remove.
            $table->string('action', 16);
            // queued, running, succeeded or failed.
            $table->string('status', 16)->index();
            // The agent's log of the operation (component.run.read).
            $table->string('run', 64)->unique();
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            // nginx (Nginx + PHP-FPM) or apache (Nginx + Apache + PHP-FPM).
            $table->string('web_server', 16)->default('nginx');
        });

        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->unsignedInteger('redis_port')->nullable()->unique();
            $table->text('redis_password')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->dropColumn(['redis_port', 'redis_password']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('web_server');
        });
        Schema::dropIfExists('component_operations');
    }
};
