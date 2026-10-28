<?php

namespace App\Http\Controllers\Sites;

use App\Http\Controllers\Controller;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Sites\SiteLogs;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads a whole site log, read from the agent in parts. Customers
 * reach only their own sites.
 */
class SiteLogController extends Controller
{
    public function __invoke(Request $request, int $site, SiteLogs $logs): StreamedResponse
    {
        $model = Site::visibleTo(auth()->user())->with('systemUser')->findOrFail($site);
        $kind = (string) $request->query('type', 'access');
        $kinds = SiteLogs::kinds($model);
        abort_unless(isset($kinds[$kind]), 404);
        try {
            $first = $logs->read($model, $kind, SiteLogs::CHUNK_BYTES, 0);
        } catch (AgentException $e) {
            abort(404, $e->forPeople());
        }
        abort_unless($first['exists'], 404);
        // The file keeps growing while it downloads; it stops at the size
        // it had when the download began.
        $size = (int) $first['size'];

        return response()->streamDownload(function () use ($logs, $model, $kind, $first, $size) {
            $chunk = $first;
            $offset = 0;
            while (true) {
                $data = substr((string) $chunk['content'], 0, max($size - $offset, 0));
                if ($data === '') {
                    return;
                }
                echo $data;
                flush();
                $offset = (int) ($chunk['next'] ?? $offset + strlen((string) $chunk['content']));
                if ($offset >= $size) {
                    return;
                }
                try {
                    $chunk = $logs->read($model, $kind, SiteLogs::CHUNK_BYTES, $offset);
                } catch (AgentException) {
                    return;
                }
            }
        }, $model->domain.'-'.$kinds[$kind][1], ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
