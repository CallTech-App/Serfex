<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Alan adları ve web sunucusu araçları: each site's subdomains and
     * redirects, its own error pages and web server directives, and
     * package limits for subdomains and additional domains.
     */
    public function up(): void
    {
        Schema::create('subdomains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->string('name')->unique();
            // Relative to the site folder: subdomains/<label> or a folder
            // inside the main site's document root.
            $table->string('web_root', 200);
            $table->timestamps();
        });

        Schema::create('site_redirects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // domain: a whole host name (and its www); path: a path on
            // the site's own names.
            $table->string('kind', 8);
            $table->string('source', 253);
            $table->boolean('with_www')->default(false);
            $table->string('target', 2000);
            $table->unsignedSmallInteger('code')->default(301);
            $table->boolean('keep_path')->default(true);
            $table->timestamps();
            $table->index(['kind', 'source']);
        });

        Schema::table('sites', function (Blueprint $table) {
            // "403", "404", "50x" => HTML.
            $table->jsonb('error_pages')->nullable();
            $table->text('nginx_directives')->nullable();
            $table->text('apache_directives')->nullable();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_subdomains')->nullable();
            $table->unsignedInteger('max_aliases')->nullable();
        });
        foreach (['Başlangıç' => [5, 2], 'Profesyonel' => [25, 10], 'Laravel Pro' => [50, 20]] as $name => [$subdomains, $aliases]) {
            DB::table('packages')->where('name', $name)->whereNull('max_subdomains')->update(['max_subdomains' => $subdomains, 'max_aliases' => $aliases]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn(['max_subdomains', 'max_aliases']));
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['error_pages', 'nginx_directives', 'apache_directives']));
        Schema::dropIfExists('site_redirects');
        Schema::dropIfExists('subdomains');
    }
};
