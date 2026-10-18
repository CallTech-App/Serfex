<?php

namespace App\Livewire\Mail;

use App\Jobs\SetupMailServer;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Agent\AgentUnavailableException;
use App\Services\Mail\MailSettings;

/**
 * The mail server's state for the e-mail pages, and its setup button.
 */
trait ChecksMailServer
{
    /**
     * State is one of ready, nohostname, missing, stopped, installing,
     * failed or offline.
     *
     * @var array{state: string, error?: ?string, services?: array<string, bool>}
     */
    public array $server = ['state' => 'ready'];

    /**
     * Polled while the mail server is being installed.
     */
    public function refreshServer(AgentClient $agent, MailSettings $settings): void
    {
        $setup = $settings->server();
        if (($setup['state'] ?? null) === 'installing') {
            $this->server = ['state' => 'installing'];

            return;
        }
        if ($settings->hostname() === null) {
            $this->server = ['state' => 'nohostname'];

            return;
        }
        try {
            $status = $agent->call('mail.status');
        } catch (AgentException $e) {
            $this->server = ['state' => 'offline', 'error' => $e instanceof AgentUnavailableException ? null : $e->reason];

            return;
        }
        $services = $status['services'] ?? [];
        $this->server = match (true) {
            ($setup['state'] ?? null) === 'failed' => ['state' => 'failed', 'error' => $setup['error'] ?? null],
            (bool) ($status['running'] ?? false) => ['state' => 'ready', 'services' => $services],
            (bool) ($status['configured'] ?? false) => ['state' => 'stopped', 'error' => $status['error'] ?? null, 'services' => $services],
            default => ['state' => 'missing'],
        };
    }

    /**
     * Install (or repair) the mail server in the background.
     */
    public function setupServer(MailSettings $settings): void
    {
        if ($settings->hostname() === null) {
            $this->redirectRoute('mail.settings', navigate: true);

            return;
        }
        $settings->saveServer(['state' => 'installing', 'error' => null]);
        $this->server = ['state' => 'installing'];
        SetupMailServer::dispatch();
    }
}
