<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The servik command line: keys it signs in with (each person's own,
     * approved in the browser), the sign-ins waiting for that approval,
     * and what a Laravel application's token may do.
     */
    public function up(): void
    {
        Schema::table('api_keys', function (Blueprint $table) {
            // panel: made under API anahtarları (full administrators);
            // cli: a command line sign-in, acting as its person.
            $table->string('kind', 8)->default('panel')->after('user_id');
            $table->string('client', 120)->nullable()->after('name');
        });

        Schema::create('cli_logins', function (Blueprint $table) {
            $table->id();
            // What the person types or sees in the browser: ABCD-2345.
            $table->string('code', 9)->unique();
            // SHA-256 of the secret the command line asks with.
            $table->string('device_hash', 64)->unique();
            // The computer's name, as the command line sends it.
            $table->string('client', 120);
            $table->string('ip', 45)->nullable();
            // pending, approved, denied or collected.
            $table->string('status', 12)->default('pending');
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('api_key_id')->nullable()->constrained()->nullOnDelete();
            // The new key until the command line collects it once.
            $table->text('secret')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        Schema::table('laravel_api_tokens', function (Blueprint $table) {
            // deploy: deploys and previews; operate: also .env, commands,
            // queues, maintenance mode and logs.
            $table->string('ability', 12)->default('deploy')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('laravel_api_tokens', fn (Blueprint $table) => $table->dropColumn('ability'));
        Schema::dropIfExists('cli_logins');
        Schema::table('api_keys', fn (Blueprint $table) => $table->dropColumn(['kind', 'client']));
    }
};
