<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * SSH access of a site's own Linux account: off, SFTP locked in its
     * home, or a shell the administrator gives. Its public keys live here;
     * its password only on the server. Packages get a feature for it.
     */
    public function up(): void
    {
        Schema::table('system_users', function (Blueprint $table) {
            $table->string('ssh_access', 8)->default('off');
            // When the password was last set; null while password logins are off.
            $table->timestamp('ssh_password_at')->nullable();
        });

        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('system_user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->text('public_key');
            $table->string('fingerprint', 100);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('allow_ssh')->default(false);
        });
        DB::table('packages')->whereIn('name', ['Profesyonel', 'Laravel Pro', 'Kurumsal'])->update(['allow_ssh' => true]);
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('allow_ssh'));
        Schema::dropIfExists('ssh_keys');
        Schema::table('system_users', fn (Blueprint $table) => $table->dropColumn(['ssh_access', 'ssh_password_at']));
    }
};
