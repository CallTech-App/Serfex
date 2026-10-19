<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\PanelUpdate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Updates\Updates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The progress of an update as JSON, for the Güncellemeler page. It also
 * answers while the panel is in maintenance for the switch (see
 * bootstrap/app.php), and keeps its shape from release to release: the
 * page of the old release reads it from the new one.
 */
class UpdateProgressController extends Controller
{
    public function __invoke(Request $request, string $run, AgentClient $agent, Updates $updates): JsonResponse
    {
        $update = PanelUpdate::where('run', $run)->firstOrFail();
        $offset = max(0, (int) $request->query('offset', 0));
        $out = ['data' => '', 'offset' => $offset, 'more' => false, 'status' => $update->status, 'error' => $update->error, 'agent' => true];
        if (in_array($update->status, ['queued', 'backing_up'], true)) {
            return response()->json($out + ['done' => false]);
        }
        try {
            $chunk = $agent->call('update.run.read', ['run' => $run, 'offset' => $offset]);
        } catch (AgentException) {
            // The agent restarts during the update.
            return response()->json(['agent' => false, 'done' => false] + $out);
        }
        $next = (int) ($chunk['offset'] ?? $offset);
        $more = $next < (int) ($chunk['size'] ?? 0);
        if (($chunk['done'] ?? false) && $update->isRunning()) {
            $updates->reconcile();
            $update->refresh();
        }

        return response()->json([
            'data' => (string) ($chunk['data'] ?? ''),
            'offset' => $next,
            'more' => $more,
            'done' => ! $more && ! $update->isRunning(),
            'status' => $update->status,
            'error' => $update->error,
            'agent' => true,
        ]);
    }
}
