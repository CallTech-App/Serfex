<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Güvenlik: each site's WAF mode, rule exclusions, password-protected
     * folders and blocked paths; security scans and their findings; and
     * two-factor login for panel accounts. Packages get a feature for the
     * site security tools.
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Null follows the server's mode; off, detect or block.
            $table->string('waf_mode', 8)->nullable();
            // URL paths Nginx refuses (a fix of the security scan).
            $table->json('denied_paths')->nullable();
        });

        Schema::create('waf_exclusions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('rule_id');
            // Empty: the whole site; otherwise a path such as /wp-admin/.
            $table->string('path', 200)->default('');
            $table->string('note', 200)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('protected_directories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained()->cascadeOnDelete();
            // URL path with slashes on both ends, /yonetim/.
            $table->string('path', 200);
            $table->string('realm', 60)->nullable();
            // [{name, hash}]: SHA-512 crypt hashes, the passwords are not kept.
            $table->json('users');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('security_scans', function (Blueprint $table) {
            $table->id();
            // server (every site and the panel's own settings) or site.
            $table->string('scope', 8);
            $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
            // manual, schedule or upload.
            $table->string('trigger', 8)->default('manual');
            $table->string('status', 12)->default('queued');
            $table->unsignedSmallInteger('sites_total')->default(0);
            $table->unsignedSmallInteger('sites_done')->default(0);
            $table->unsignedInteger('files')->default(0);
            $table->boolean('clamav')->default(false);
            $table->text('error')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        Schema::create('security_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('scan_id')->nullable()->constrained('security_scans')->nullOnDelete();
            // Null: a finding about the server or the panel itself.
            $table->foreignId('site_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('check', 16);
            $table->string('code', 32);
            // critical, high, medium or low.
            $table->string('severity', 8);
            // Relative to the site's folder.
            $table->string('path', 1000)->default('');
            $table->string('url', 1000)->nullable();
            $table->string('detail', 300)->nullable();
            $table->json('fixes');
            $table->unsignedInteger('count')->default(0);
            // Site, check, code and path: the same problem across scans.
            $table->string('fingerprint', 64)->index();
            // open, fixed, ignored or gone (no longer found).
            $table->string('status', 8)->default('open');
            $table->string('fix_action', 16)->nullable();
            // What undoing the fix needs: trash id, old modes or owners.
            $table->json('fix_data')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'severity']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery_codes')->nullable();
            $table->timestamp('two_factor_confirmed_at')->nullable();
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->boolean('allow_security')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('packages', fn (Blueprint $table) => $table->dropColumn('allow_security'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at']));
        Schema::dropIfExists('security_findings');
        Schema::dropIfExists('security_scans');
        Schema::dropIfExists('protected_directories');
        Schema::dropIfExists('waf_exclusions');
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['waf_mode', 'denied_paths']));
    }
};
