<?php

use App\Jobs\ReapplyFirewall;
use App\Services\Security\Firewall;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Until 0.1.66 the firewall's rules were written only when it was
     * saved, so a server that turned it on (in the setup wizard) before
     * setting up mail kept the mail ports closed: sending worked, mail
     * from other servers never arrived. Writing the rules again once opens
     * them on the servers already set up.
     */
    public function up(): void
    {
        if (app(Firewall::class)->enabled()) {
            ReapplyFirewall::dispatch();
        }
    }

    public function down(): void {}
};
