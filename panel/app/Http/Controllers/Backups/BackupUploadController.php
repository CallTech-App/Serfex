<?php

namespace App\Http\Controllers\Backups;

use App\Http\Controllers\Controller;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\BackupImports;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives an archive uploaded from Yedekler in chunks (the request body)
 * and hands each to the agent, which appends it to the upload. The page
 * imports the upload once every chunk is in. Administrators only.
 */
class BackupUploadController extends Controller
{
    public function __invoke(Request $request, string $upload, AgentClient $agent): JsonResponse
    {
        $offset = (int) $request->query('offset', 0);
        $data = $request->getContent();
        if ($offset < 0 || strlen($data) > BackupImports::UPLOAD_CHUNK) {
            return response()->json(['error' => 'Parça çok büyük.'], 422);
        }
        try {
            $result = $agent->call('backup.upload.write', ['upload' => $upload, 'offset' => $offset, 'data' => base64_encode($data)], 120);
        } catch (AgentException $e) {
            return response()->json(['error' => $e->reason ?: 'Sunucu ajanına ulaşılamıyor.'], 422);
        }

        return response()->json(['size' => (int) ($result['size'] ?? $offset + strlen($data))]);
    }
}
