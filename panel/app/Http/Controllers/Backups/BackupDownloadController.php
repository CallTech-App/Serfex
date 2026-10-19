<?php

namespace App\Http\Controllers\Backups;

use App\Http\Controllers\Controller;
use App\Models\BackupItem;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams one archive of a backup from the server, read from the agent in
 * chunks: a plain tar with manifest.json, the site's files, database dumps,
 * mail and site.json. Customers download their own sites' archives; a
 * backup that is only on its target is fetched first from the page.
 */
class BackupDownloadController extends Controller
{
    public function __invoke(int $item, AgentClient $agent): StreamedResponse
    {
        $user = auth()->user();
        $file = BackupItem::visibleTo($user)->with('backup')->where('status', 'succeeded')->findOrFail($item);
        abort_unless($file->backup->isUsable() && $file->backup->onServer(), 404);
        abort_if($file->isPanel() && ! $user->is_admin, 403);
        abort_unless($user->allows('backups'), 403);
        $key = $file->backup->key;
        $name = $file->name;

        return response()->streamDownload(function () use ($agent, $key, $name) {
            $offset = 0;
            do {
                try {
                    $chunk = $agent->call('backup.read', ['key' => $key, 'name' => $name, 'offset' => $offset], config('servik.agent.job_timeout'));
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
