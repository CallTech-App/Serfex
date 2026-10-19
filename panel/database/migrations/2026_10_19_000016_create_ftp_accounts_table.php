<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SFTP accounts of sites, each locked in one folder of its site. The
     * server holds the password; the panel holds who owns what. Packages
     * get a limit for them.
     */
    public function up(): void
    {
        Schema::create('ftp_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // The site's account: its prefix, its UID on the server, and
            // the customer quotas count it for.
            $table->foreignId('system_user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 32)->unique();
            // Folder inside the site's folder; '' is the whole site folder.
            $table->string('directory', 200)->default('');
            $table->string('note', 100)->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unsignedInteger('max_ftp_accounts')->nullable();
        });
        foreach (['Başlangıç' => 2, 'Profesyonel' => 10, 'Laravel Pro' => 20] as $name => $max) {
            DB::table('packages')->where('name', $name)->whereNull('max_ftp_accounts')->update(['max_ftp_accounts' => $max]);
        }
    }

    public function down(): void
    {
        Schema::table('packages', function (Blueprint $table) {
            $table->dropColumn('max_ftp_accounts');
        });
        Schema::dropIfExists('ftp_accounts');
    }
};
