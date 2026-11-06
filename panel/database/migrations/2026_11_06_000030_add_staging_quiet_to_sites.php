<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // A staging copy's WordPress sends no e-mail and runs no
            // WP-Cron while these are on (Serfex's staging plugin).
            $table->boolean('copy_blocks_mail')->default(true);
            $table->boolean('copy_blocks_cron')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['copy_blocks_mail', 'copy_blocks_cron']);
        });
    }
};
