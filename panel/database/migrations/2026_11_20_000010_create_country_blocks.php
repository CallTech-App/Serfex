<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Güvenlik > IP engelleri > Ülkeler: the countries whose visitors the
     * server refuses. The scope (sites only, or the whole server) is one
     * setting for all of them.
     */
    public function up(): void
    {
        Schema::create('country_blocks', function (Blueprint $table) {
            $table->id();
            // ISO 3166-1 alpha-2, upper case.
            $table->char('code', 2)->unique();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('country_blocks');
    }
};
