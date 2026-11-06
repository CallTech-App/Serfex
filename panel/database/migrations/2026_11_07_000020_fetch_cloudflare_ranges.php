<?php

use App\Services\Cloudflare\CloudflareRanges;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * The agent starts with the Cloudflare ranges it was built with; the
     * weekly update fetches Cloudflare's current list. Fetch it once now
     * so a server that updates does not wait for Monday.
     */
    public function up(): void
    {
        if (app()->runningUnitTests()) {
            return;
        }
        dispatch(function () {
            try {
                app(CloudflareRanges::class)->update();
            } catch (Throwable $e) {
                Log::warning('Cloudflare ranges not updated: '.$e->getMessage());
            }
        });
    }

    public function down(): void {}
};
