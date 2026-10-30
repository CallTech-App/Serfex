<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a failed site was doing when it failed: "delete" for a deletion
     * that did not finish (the site page offers to delete it again),
     * otherwise null (creating or changing it; Tekrar dene applies it again).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('failed_action', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('failed_action'));
    }
};
