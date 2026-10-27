<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Removes a certificate that no site uses any more from the server and the
 * panel. Queued with a delay after a swap, so Undo can take it back.
 */
class DeleteCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public Certificate $certificate) {}

    public function handle(AgentClient $agent): void
    {
        $certificate = Certificate::find($this->certificate->id);
        if ($certificate === null || $certificate->users()->exists()) {
            return;
        }
        try {
            $agent->call('ssl.delete', ['name' => $certificate->name], config('serfex.agent.job_timeout'));
        } catch (AgentException $e) {
            if ($e->getCode() === AgentException::INVALID_PARAMS) {
                // Still used by a vhost on the server; leave it.
                return;
            }
            throw $e;
        }
        $certificate->delete();
    }
}
