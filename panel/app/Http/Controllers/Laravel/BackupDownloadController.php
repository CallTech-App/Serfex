<?php

namespace App\Http\Controllers\Laravel;

use App\Http\Controllers\Controller;
use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a backup archive from the server, read from the agent in
 * chunks. The archive is a plain tar: manifest.json, code.tar.gz, env,
 * storage.tar.gz and the database dump.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(int $app, int $backup, AgentClient $agent): StreamedResponse
    {
        $model = LaravelApp::with('site')->findOrFail($app);
        abort_unless(auth()->user()->canOnApp($model, 'operate'), 403);
        $file = $model->backups()->where('status', 'succeeded')->findOrFail($backup);
        $domain = $model->site->domain;

        return response()->streamDownload(function () use ($agent, $domain, $file) {
            $offset = 0;
            do {
                try {
                    $chunk = $agent->call('laravel.backup.read', ['domain' => $domain, 'id' => $file->key, 'offset' => $offset], config('serfex.agent.job_timeout'));
                } catch (AgentException) {
                    return;
                }
                $data = base64_decode((string) ($chunk['data'] ?? ''), true);
                if ($data === false || $data === '') {
                    return;
                }
                echo $data;
                flush();
                $offset = (int) $chunk['offset'];
            } while ($offset < (int) ($chunk['size'] ?? 0));
        }, $file->fileName(), ['Content-Type' => 'application/x-tar']);
    }
}
