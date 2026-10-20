<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Languages: each person's own choice of language (null: the server's
     * default), and the notification texts in every language of the panel,
     * so each administrator reads them in their own.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 8)->nullable()->after('role');
        });
        Schema::table('notification_events', function (Blueprint $table) {
            // locale => {title, detail}
            $table->json('texts')->nullable()->after('detail');
        });
    }

    public function down(): void
    {
        Schema::table('notification_events', fn (Blueprint $table) => $table->dropColumn('texts'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('locale'));
    }
};
