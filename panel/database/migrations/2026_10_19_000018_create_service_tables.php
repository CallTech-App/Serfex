<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hizmetler ve PHP sürümleri: each site's own PHP settings and PHP-FPM
     * pool options, and what a hosting package lets its customers change
     * there. Component operations remember which PHP extension they
     * install or remove.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // {ini: {key: value}, custom: "key = value\n...", pm, max_children,
            // max_requests, request_timeout, allow_functions}; null = defaults.
            $table->json('php_settings')->nullable();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('allow_php_settings')->default(true);
            // Highest memory_limit customers may give a site; null = no cap.
            $table->unsignedInteger('max_php_memory_mb')->nullable();
        });
        $caps = ['Başlangıç' => 256, 'Profesyonel' => 512, 'Laravel Pro' => 1024];
        foreach ($caps as $name => $mb) {
            DB::table('packages')->where('name', $name)->update(['max_php_memory_mb' => $mb]);
        }

        Schema::table('component_operations', function (Blueprint $table) {
            // The PHP extension of an extension install or removal.
            $table->string('detail', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('component_operations', fn (Blueprint $table) => $table->dropColumn('detail'));
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['allow_php_settings', 'max_php_memory_mb']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('php_settings'));
    }
};
