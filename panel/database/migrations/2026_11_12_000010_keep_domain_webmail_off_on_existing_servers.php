<?php

use App\Models\Setting;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * 0.1.75 gives every mail domain its own webmail address
     * (webmail.<domain>): a DNS record and a certificate each. A server
     * whose mail was set up before keeps things as they are until an
     * administrator turns it on in E-posta ayarları, which offers it.
     */
    public function up(): void
    {
        if (Setting::get('mail.domain_webmail') === null && (Setting::get('mail.server')['setup_at'] ?? null) !== null) {
            Setting::put('mail.domain_webmail', false);
            Setting::put('mail.domain_webmail_offer', true);
        }
    }

    public function down(): void {}
};
