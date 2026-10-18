<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A Linux account that owns one or more sites. Later milestones attach
     * it to a customer and a hosting plan.
     */
    public function up(): void
    {
        Schema::create('system_users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 32)->unique();
            $table->unsignedInteger('uid')->nullable();
            $table->string('home')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_users');
    }
};
