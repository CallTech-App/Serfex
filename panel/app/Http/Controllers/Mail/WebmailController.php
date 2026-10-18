<?php

namespace App\Http\Controllers\Mail;

use App\Enums\MailDomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailSettings;
use Illuminate\Http\RedirectResponse;

/**
 * Opens a mailbox in webmail without its password: the agent hands out a
 * one-time sign-in link that is valid for a minute.
 */
class WebmailController extends Controller
{
    public function __invoke(Mailbox $mailbox, AgentClient $agent, MailSettings $settings): RedirectResponse
    {
        $domain = $mailbox->domain;
        if ($settings->webmailUrl() === null || $domain->status === MailDomainStatus::Deleting) {
            return $this->fail($mailbox, 'Web posta kapalı. E-posta ayarlarından açabilirsiniz.');
        }

        try {
            $login = $agent->call('mail.webmail.login', ['address' => $mailbox->address()], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            return $this->fail($mailbox, 'Web postaya giriş yapılamadı: '.$e->reason);
        }

        return redirect()->away($login['url']);
    }

    private function fail(Mailbox $mailbox, string $message): RedirectResponse
    {
        return redirect()->route('mail.show', $mailbox->mail_domain_id)->with('toast', $message);
    }
}
