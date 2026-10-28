<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A new sign-in address waits here until the link mailed to it is
     * opened; only the link's hash is stored.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('pending_email', 190)->nullable();
            $table->string('pending_email_token', 64)->nullable()->unique();
            $table->timestamp('pending_email_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['pending_email', 'pending_email_token', 'pending_email_at']));
    }
};
