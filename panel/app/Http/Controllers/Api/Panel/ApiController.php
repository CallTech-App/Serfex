<?php

namespace App\Http\Controllers\Api\Panel;

use App\Http\Middleware\AuthenticateApiKey;
use App\Models\Site;
use App\Models\User;
use App\Services\Audit\AuditLog;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Shared by the panel API's controllers: every change is written to the
 * İşlem günlüğü as "API: <key name>", and what a person may see is what
 * they see in the panel (Site::visibleTo and its kin).
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

    /**
     * Stops the request with a 404 and $message.
     */
    protected function missing(string $message): never
    {
        throw new HttpResponseException($this->refuse($message, 404));
    }

    protected function user(Request $request): User
    {
        return $request->user();
    }

    /**
     * The site named $domain, when the caller may see it.
     */
    protected function findSite(Request $request, string $domain, array $with = []): Site
    {
        return Site::visibleTo($this->user($request))->with(['systemUser.customer.package', 'certificate', ...$with])
            ->where('domain', mb_strtolower(rtrim($domain, '.')))->first()
            ?? $this->missing(__(':domain adında bir site yok.', ['domain' => $domain]));
    }
}
