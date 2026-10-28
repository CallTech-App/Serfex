<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which of a site's domain and its www. name visitors end up on: the
     * bare domain, the www. name, or neither (both open the site). Sites
     * that exist keep opening on both; new sites choose when they are made.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('preferred_host', 8)->default('none');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('preferred_host');
        });
    }
};
