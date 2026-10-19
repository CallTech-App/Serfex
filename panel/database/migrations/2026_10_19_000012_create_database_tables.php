<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customer databases on MySQL/MariaDB or PostgreSQL, their users and
     * which user may do what on which database. The servers hold the data
     * and the passwords; the panel holds who owns what.
     */
    public function up(): void
    {
        Schema::create('databases', function (Blueprint $table) {
            $table->id();
            // mysql (MySQL or MariaDB) or postgresql.
            $table->string('server', 20);
            $table->string('name', 64);
            // The account whose prefix the name carries; quotas count it
            // for the account's customer.
            $table->foreignId('system_user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // As the agent last measured it.
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->unsignedInteger('tables')->nullable();
            $table->timestamp('measured_at')->nullable();
            // Set while a deletion waits out its undo window: whether a
            // backup is taken before the database goes.
            $table->boolean('backup_on_delete')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['server', 'name']);
        });

        Schema::create('database_users', function (Blueprint $table) {
            $table->id();
            $table->string('server', 20);
            $table->string('name', 32);
            $table->foreignId('system_user_id')->nullable()->constrained()->nullOnDelete();
            // Other machines the user may connect from: %, addresses,
            // 10.8.0.% wildcards or CIDR ranges. Empty: only this server.
            $table->jsonb('hosts')->default('[]');
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['server', 'name']);
        });

        Schema::create('database_grants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('database_id')->constrained()->cascadeOnDelete();
            $table->foreignId('database_user_id')->constrained()->cascadeOnDelete();
            // all, readwrite or readonly.
            $table->string('access', 12);
            $table->timestamps();
            $table->unique(['database_id', 'database_user_id']);
        });

        Schema::table('laravel_apps', function (Blueprint $table) {
            // The database Servik created for the application, if any.
            $table->foreignId('database_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('laravel_apps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('database_id');
        });
        Schema::dropIfExists('database_grants');
        Schema::dropIfExists('database_users');
        Schema::dropIfExists('databases');
    }
};
