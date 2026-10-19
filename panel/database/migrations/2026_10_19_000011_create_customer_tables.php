<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hosting packages and the customers who use them. A customer owns
     * Linux accounts (and through them sites, mail, DNS and Laravel
     * applications) and signs in to the panel with their own login.
     */
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60)->unique();
            $table->boolean('is_default')->default(false);
            // Limits; null means unlimited.
            $table->unsignedInteger('max_sites')->nullable();
            $table->unsignedInteger('max_disk_mb')->nullable();
            $table->unsignedInteger('max_traffic_gb')->nullable();
            $table->unsignedInteger('max_mailboxes')->nullable();
            $table->unsignedInteger('max_databases')->nullable();
            $table->unsignedInteger('max_laravel_apps')->nullable();
            // PHP versions new sites may use; null allows every installed one.
            $table->jsonb('php_versions')->nullable();
            $table->boolean('allow_laravel')->default(true);
            // Artisan, Composer and npm commands and Tinker in the browser.
            $table->boolean('allow_shell')->default(false);
            $table->boolean('allow_mail')->default(true);
            $table->boolean('allow_dns')->default(true);
            $table->timestamps();
        });

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('package_id')->constrained()->restrictOnDelete();
            $table->string('company', 120)->nullable();
            $table->string('name', 100);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->text('notes')->nullable();
            // The Linux account new sites are created under.
            $table->string('username', 32)->unique();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspend_reason', 200)->nullable();
            // Set while a deletion waits out its undo window: whether the
            // customer's sites go too.
            $table->boolean('delete_sites')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::table('system_users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->nullOnDelete();
            // The home directory's size, as the agent last measured it.
            $table->unsignedBigInteger('disk_bytes')->nullable();
            $table->timestamp('disk_measured_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        Schema::create('site_traffic', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->date('month');
            // Bytes sent to visitors (response bodies).
            $table->unsignedBigInteger('bytes')->default(0);
            $table->timestamps();

            $table->unique(['site_id', 'month']);
        });

        // Presets to start from; every value can be changed.
        $now = now();
        $gb = 1024;
        DB::table('packages')->insert([
            ['name' => 'Başlangıç', 'is_default' => false, 'max_sites' => 1, 'max_disk_mb' => 5 * $gb, 'max_traffic_gb' => 100, 'max_mailboxes' => 5, 'max_databases' => 2, 'max_laravel_apps' => 0,
                'allow_laravel' => false, 'allow_shell' => false, 'allow_mail' => true, 'allow_dns' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Profesyonel', 'is_default' => true, 'max_sites' => 5, 'max_disk_mb' => 20 * $gb, 'max_traffic_gb' => 500, 'max_mailboxes' => 25, 'max_databases' => 10, 'max_laravel_apps' => 2,
                'allow_laravel' => true, 'allow_shell' => false, 'allow_mail' => true, 'allow_dns' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Laravel Pro', 'is_default' => false, 'max_sites' => 10, 'max_disk_mb' => 50 * $gb, 'max_traffic_gb' => 1000, 'max_mailboxes' => 25, 'max_databases' => 20, 'max_laravel_apps' => 10,
                'allow_laravel' => true, 'allow_shell' => true, 'allow_mail' => true, 'allow_dns' => true, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Kurumsal', 'is_default' => false, 'max_sites' => null, 'max_disk_mb' => 100 * $gb, 'max_traffic_gb' => 2000, 'max_mailboxes' => null, 'max_databases' => null, 'max_laravel_apps' => null,
                'allow_laravel' => true, 'allow_shell' => true, 'allow_mail' => true, 'allow_dns' => true, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('site_traffic');
        Schema::table('users', fn (Blueprint $table) => $table->dropConstrainedForeignId('customer_id'));
        Schema::table('system_users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('customer_id');
            $table->dropColumn(['disk_bytes', 'disk_measured_at']);
        });
        Schema::dropIfExists('customers');
        Schema::dropIfExists('packages');
    }
};
