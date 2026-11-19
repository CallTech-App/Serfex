<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Güvenlik > IP engelleri: the country of a blocked address, how and
     * by whom a block ended (for Geçmiş), and the addresses that are never
     * blocked.
     */
    public function up(): void
    {
        Schema::table('ip_blocks', function (Blueprint $table) {
            // Where the address is registered (ISO 3166), ZZ when the
            // country table does not know it, null until it was looked up.
            $table->char('country', 2)->nullable();
            // Set with removed_at. How the block ended: user (lifted by
            // hand), allowed (the address went on the allowed list),
            // replaced (a Fail2ban ban turned into a panel block) or sync
            // (Fail2ban lifted its own ban). A block that simply ran out
            // has neither.
            $table->string('ended', 16)->nullable();
            $table->foreignId('removed_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::create('ip_allows', function (Blueprint $table) {
            $table->id();
            // An address or a prefix, as the agent normalised it.
            $table->string('address', 64)->unique();
            $table->char('country', 2)->nullable();
            $table->string('note', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_allows');
        Schema::table('ip_blocks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('removed_by');
            $table->dropColumn(['country', 'ended']);
        });
    }
};
