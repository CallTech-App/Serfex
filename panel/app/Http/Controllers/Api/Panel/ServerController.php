<?php

namespace App\Http\Controllers\Api\Panel;

use App\Models\Customer;
use App\Models\Setting;
use App\Models\Site;
use App\Services\Settings\Notifications;
use App\Services\Settings\PanelSettings;
use Illuminate\Http\JsonResponse;

class ServerController extends ApiController
{
    /**
     * GET /api/v1/server: the panel, its server and what is wrong now.
     */
    public function show(PanelSettings $settings, Notifications $notifications): JsonResponse
    {
        $problems = [];
        foreach ($notifications->problems() as $key => [$kind, $title, $detail]) {
            $problems[] = ['key' => $key, 'kind' => $kind, 'title' => $title, 'detail' => $detail];
        }

        return response()->json(['data' => [
            'hostname' => Setting::get('server.hostname') ?: gethostname(),
            'panel_hostname' => Setting::get('panel.hostname'),
            'panel_port' => $settings->port(),
            'version' => config('servik.version'),
            'timezone' => $settings->timezone(),
            'sites' => Site::count(),
            'customers' => Customer::count(),
            'problems' => $problems,
        ]]);
    }
}
