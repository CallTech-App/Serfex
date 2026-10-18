<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_user_id')->constrained()->restrictOnDelete();
            $table->string('domain', 253)->unique();
            $table->jsonb('aliases')->default('[]');
            $table->string('php_version', 8);
            $table->string('document_root')->nullable();
            $table->string('status', 16)->index();
            $table->text('last_error')->nullable();
            $table->timestamp('provisioned_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
