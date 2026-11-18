<?php

namespace App\Services\Settings;

use App\Mail\PanelMail;
use App\Models\Setting;
use App\Services\Mail\MailSettings;
use App\Services\Mail\ReverseDns;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * How the panel sends its own mail (notifications, invitations): through
 * this server's mail server (Postfix, once E-posta is set up) or an SMTP
 * server of the administrator's choice. The SMTP password is kept
 * encrypted.
 */
class OutgoingMail
{
    public const KEY = 'panel.mail';

    /** coreutils' timeout, which stops a sendmail that does not finish. */
    private const TIMEOUT = '/usr/bin/timeout';

    /** Encryption choices: key => name. */
    public const ENCRYPTION = [
        'starttls' => 'STARTTLS (587)',
        'tls' => 'SSL/TLS (465)',
        'none' => 'Yok',
    ];

    private const DEFAULTS = [
        'mode' => 'server',
        'host' => '',
        'port' => 587,
        'encryption' => 'starttls',
        'username' => '',
        'password' => null,
        'from_address' => '',
        'from_name' => 'Serfex',
    ];

    /**
     * The saved settings; the password stays encrypted.
     *
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return array_replace(self::DEFAULTS, (array) Setting::get(self::KEY, []));
    }

    /**
     * @param  array<string, mixed>  $values  password null keeps the saved one
     */
    public function save(array $values): void
    {
        $settings = array_replace($this->settings(), array_intersect_key($values, self::DEFAULTS));
        if (array_key_exists('password', $values) && $values['password'] !== null) {
            $settings['password'] = $values['password'] === '' ? null : Crypt::encryptString($values['password']);
        } else {
            $settings['password'] = $this->settings()['password'];
        }
        Setting::put(self::KEY, $settings);
    }

    /**
     * Why mail can't be sent now, or null when it can.
     */
    public function problem(): ?string
    {
        $s = $this->settings();
        if ($s['mode'] === 'smtp') {
            return $s['host'] === '' ? __('SMTP sunucusu girilmedi.') : null;
        }

        return app(MailSettings::class)->isSetUp() || is_executable((string) config('serfex.mail.sendmail'))
            ? null
            : __('Bu sunucuda posta sunucusu kurulu değil. E-posta bölümünden kurun ya da bir SMTP sunucusu girin.');
    }

    /**
     * Why mail the panel sends may be refused on the way although it
     * leaves: this server's reverse DNS when it sends through Postfix.
     */
    public function deliveryWarning(): ?string
    {
        if ($this->problem() !== null || $this->settings()['mode'] === 'smtp') {
            return null;
        }

        return app(ReverseDns::class)->problem();
    }

    public function fromAddress(): string
    {
        $s = $this->settings();
        if ($s['from_address'] !== '') {
            return $s['from_address'];
        }
        $host = Setting::get('panel.hostname') ?: Setting::get('mail.hostname') ?: 'localhost.localdomain';

        return 'serfex@'.preg_replace('/^(panel|mail)\./', '', (string) $host);
    }

    /**
     * Sends $body to $to. Returns null, or why it failed.
     *
     * @param  list<string>  $to
     */
    public function send(array $to, string $subject, string $body): ?string
    {
        if ($to === []) {
            return __('Alıcı yok.');
        }
        if ($problem = $this->problem()) {
            return $problem;
        }
        $started = microtime(true);
        try {
            $mail = (new PanelMail($subject, $body))->from($this->fromAddress(), $this->settings()['from_name'] ?: 'Serfex');
            $this->mailer()->to($to)->send($mail);
        } catch (Throwable $e) {
            $limit = $this->sendmailTimeout();
            if ($this->settings()['mode'] !== 'smtp' && $limit !== null && microtime(true) - $started >= $limit) {
                return __('Sunucunun posta programı (sendmail) :seconds saniye içinde yanıt vermedi ve durduruldu.', ['seconds' => $limit]);
            }

            return Str::limit($e->getMessage(), 300);
        }

        return null;
    }

    /**
     * sendmail speaking SMTP (-bs), so a refusal comes back as an answer.
     * It runs under timeout(1): sendmail has no time limit of its own, and
     * Postfix's waits forever when it cannot write to the mail queue.
     * timeout stops it and what it started.
     */
    public function sendmailCommand(): string
    {
        $command = config('serfex.mail.sendmail').' -bs -i';
        $limit = $this->sendmailTimeout();

        return $limit === null ? $command : self::TIMEOUT.' -k 5 '.$limit.' '.$command;
    }

    /**
     * Seconds sendmail gets, or null where it cannot be limited.
     */
    private function sendmailTimeout(): ?int
    {
        $seconds = (int) config('serfex.mail.sendmail_timeout');

        return $seconds > 0 && is_executable(self::TIMEOUT) ? $seconds : null;
    }

    private function mailer(): Mailer
    {
        $s = $this->settings();
        $config = $s['mode'] === 'smtp' ? [
            'transport' => 'smtp',
            'scheme' => $s['encryption'] === 'tls' ? 'smtps' : 'smtp',
            'host' => $s['host'],
            'port' => (int) $s['port'],
            'username' => $s['username'] ?: null,
            'password' => $s['password'] ? Crypt::decryptString($s['password']) : null,
            'timeout' => 15,
            'auto_tls' => $s['encryption'] !== 'none',
        ] : [
            'transport' => 'sendmail',
            'path' => $this->sendmailCommand(),
        ];
        config(['mail.mailers.serfex' => $config]);
        Mail::purge('serfex');

        return Mail::mailer('serfex');
    }
}
