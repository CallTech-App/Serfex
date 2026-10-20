<?php

namespace App\Http\Controllers\Api\Panel;

use App\Http\Middleware\AuthenticateApiKey;
use App\Services\Audit\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by the panel API's controllers: every change is written to the
 * İşlem günlüğü as "API: <key name>".
 */
abstract class ApiController
{
    protected function audit(Request $request, string $section, string $message, ?string $url = null): void
    {
        $key = AuthenticateApiKey::key($request);
        AuditLog::record($message, $request->user(), 'api', [
            'actor' => 'API: '.$key->name.' ('.$request->user()->name.')',
            'section' => $section,
            'url' => $url,
        ]);
    }

    protected function refuse(string $message, int $status = 422): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
