<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Disk limits enforced by the file system's user quotas: an
     * administrator may give one customer another disk limit than their
     * package's (disk_override, with disk_override_mb null for unlimited),
     * disk_full_at is when the customer's disk filled up (writes and
     * incoming mail are refused from then on), and quota_limit_bytes is
     * the limit last set on an account's Linux user.
     */
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->boolean('disk_override')->default(false)->after('package_id');
            $table->unsignedInteger('disk_override_mb')->nullable()->after('disk_override');
            $table->timestamp('disk_full_at')->nullable()->after('suspend_reason');
        });
        Schema::table('system_users', function (Blueprint $table) {
            $table->unsignedBigInteger('quota_limit_bytes')->nullable()->after('disk_measured_at');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['disk_override', 'disk_override_mb', 'disk_full_at']);
        });
        Schema::table('system_users', function (Blueprint $table) {
            $table->dropColumn('quota_limit_bytes');
        });
    }
};
