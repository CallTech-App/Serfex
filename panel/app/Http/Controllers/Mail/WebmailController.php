<?php

namespace App\Http\Controllers\Mail;

use App\Enums\MailDomainStatus;
use App\Http\Controllers\Controller;
use App\Models\Mailbox;
use App\Models\MailDomain;
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
        $user = auth()->user();
        $domain = MailDomain::visibleTo($user)->find($mailbox->mail_domain_id);
        abort_if($domain === null || ! $user->allows('mail'), 404);
        if ($domain->site?->isSuspended()) {
            return $this->fail($mailbox, __('Hesap askıda; web postaya girilemez.'));
        }
        if ($settings->webmailUrl() === null || $domain->status === MailDomainStatus::Deleting) {
            return $this->fail($mailbox, __('Web posta kapalı. E-posta ayarlarından açabilirsiniz.'));
        }

        try {
            $login = $agent->call('mail.webmail.login', ['address' => $mailbox->address()], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            return $this->fail($mailbox, __('Web postaya giriş yapılamadı: :reason', ['reason' => $e->reason]));
        }

        return redirect()->away($login['url']);
    }

    private function fail(Mailbox $mailbox, string $message): RedirectResponse
    {
        return redirect()->route('mail.show', $mailbox->mail_domain_id)->with('toast', $message);
    }
}
