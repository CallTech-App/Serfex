<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Ubuntu packages an upgrade from Yazılım Yönetimi → Ubuntu
     * güncellemeleri was asked for (null: all of them).
     */
    public function up(): void
    {
        Schema::table('component_operations', function (Blueprint $table) {
            $table->json('packages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('component_operations', fn (Blueprint $table) => $table->dropColumn('packages'));
    }
};
