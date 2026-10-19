<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Files\FileManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The file manager's transfers, which do not fit through Livewire:
 * downloads (streamed from the agent in chunks), uploads (sent in chunks
 * by the page) and the editor's text, which may be larger than a
 * Livewire request. Customers reach only their own sites.
 */
class SiteFileController extends Controller
{
    public function __construct(private readonly FileManager $files) {}

    private function site(int $site): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser')->findOrFail($site);
    }

    public function download(Request $request, int $site): StreamedResponse
    {
        $model = $this->site($site);
        $path = (string) $request->query('yol', '');
        $files = $this->files;
        // The first chunk answers missing or forbidden files before the
        // download starts.
        try {
            $first = $files->call($model, 'read', ['path' => $path, 'offset' => 0]);
        } catch (AgentException $e) {
            abort(404, FileManager::message($e));
        }

        return response()->streamDownload(function () use ($files, $model, $path, $first) {
            $chunk = $first;
            while (true) {
                $data = base64_decode((string) ($chunk['data'] ?? ''), true);
                if ($data === false || $data === '') {
                    return;
                }
                echo $data;
                flush();
                $offset = (int) $chunk['offset'];
                if ($offset >= (int) ($chunk['size'] ?? 0)) {
                    return;
                }
                try {
                    $chunk = $files->call($model, 'read', ['path' => $path, 'offset' => $offset]);
                } catch (AgentException) {
                    return;
                }
            }
        }, basename($path) ?: 'dosya', ['Content-Type' => 'application/octet-stream', 'Content-Length' => (string) (int) ($first['size'] ?? 0)]);
    }

    public function text(Request $request, int $site): JsonResponse
    {
        $model = $this->site($site);
        try {
            $res = $this->files->call($model, 'text', ['path' => (string) $request->query('yol', '')]);
        } catch (AgentException $e) {
            return response()->json(['error' => FileManager::message($e), 'code' => explode(':', $e->reason)[0]], 422);
        }

        return response()->json(['content' => $res['content'] ?? '', 'mtime' => (int) ($res['mtime'] ?? 0), 'size' => (int) ($res['size'] ?? 0)]);
    }

    public function save(Request $request, int $site): JsonResponse
    {
        $model = $this->site($site);
        // The raw body: the request's input is trimmed and empty strings
        // become null, which would change the file.
        $input = json_decode($request->getContent(), true);
        $input = is_array($input) ? $input : [];
        $content = $input['content'] ?? null;
        if (! is_string($content) || strlen($content) > FileManager::MAX_EDIT) {
            return response()->json(['error' => 'Dosya düzenleyici için çok büyük (en fazla 2 MB).'], 422);
        }
        try {
            $res = $this->files->call($model, 'save', [
                'path' => (string) ($input['path'] ?? ''),
                'data' => base64_encode($content),
                'mtime' => (int) ($input['mtime'] ?? 0),
            ]);
        } catch (AgentException $e) {
            return response()->json(['error' => FileManager::message($e), 'code' => explode(':', $e->reason)[0]], 422);
        }

        return response()->json(['mtime' => (int) ($res['mtime'] ?? 0), 'trash' => (string) ($res['trash'] ?? '')]);
    }

    /**
     * One chunk of an upload (the request body). The last chunk moves the
     * file into place; an existing file is replaced only when asked, and
     * goes to the trash.
     */
    public function upload(Request $request, int $site): JsonResponse
    {
        $model = $this->site($site);
        $offset = (int) $request->query('offset', 0);
        $data = $request->getContent();
        if ($offset < 0 || strlen($data) > FileManager::CHUNK) {
            return response()->json(['error' => 'Parça çok büyük.'], 422);
        }
        if ($offset + strlen($data) > FileManager::MAX_UPLOAD) {
            return response()->json(['error' => 'Dosyalar en fazla 2 GB olabilir. Daha büyükleri SFTP ile yükleyin.'], 422);
        }
        if ($offset === 0 && ($refusal = $this->files->uploadRefusal($model))) {
            return response()->json(['error' => $refusal], 422);
        }
        try {
            $res = $this->files->call($model, 'upload', [
                'path' => (string) $request->query('yol', ''),
                'name' => (string) $request->query('ad', ''),
                'upload' => (string) $request->query('parca', ''),
                'offset' => $offset,
                'data' => base64_encode($data),
                'final' => $request->boolean('son'),
                'overwrite' => $request->boolean('uzerine'),
            ]);
        } catch (AgentException $e) {
            return response()->json(['error' => FileManager::message($e), 'code' => explode(':', $e->reason)[0]], 422);
        }

        return response()->json(['size' => (int) ($res['size'] ?? 0), 'path' => (string) ($res['path'] ?? ''), 'trash' => (string) ($res['trash'] ?? '')]);
    }
}
