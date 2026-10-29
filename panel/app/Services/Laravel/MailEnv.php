<?php

namespace App\Services\Laravel;

use App\Models\Mailbox;
use App\Models\Site;
use App\Services\Mail\MailPassword;
use App\Services\Mail\MailSettings;

/**
 * Whether an application can send mail from this server, and the .env
 * values of a mailbox login that make it so.
 *
 * A site sends only with a mailbox login on port 587, like any mail
 * client: sendmail (PHP's mail()) is refused to sites' system users and
 * unauthenticated SMTP from the server is relayed nowhere, since mail
 * nobody logged in for is what spam from a hacked site looks like.
 * Laravel's .env.example leaves MAIL_MAILER=log (or SMTP to
 * 127.0.0.1:2525 without a login) and hello@example.com, so a new
 * application sends nothing until its owner opens a mailbox for it.
 *
 * An application that uses an outside SMTP server or a mail API (SES,
 * Postmark, Mailgun…) is its owner's business; only an example sender
 * address is reported there.
 */
class MailEnv
{
    public const PORT = '587';

    /** Mailers that do not send: the message goes to the log or memory. */
    private const NOT_SENDING = ['', 'log', 'array'];

    /** Mailers that hand the message to sendmail. */
    private const SENDMAIL = ['sendmail', 'mail', 'native'];

    private const LOCAL_HOSTS = ['', '127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0', 'mailpit', 'mailhog'];

    /** WordPress plugins that send through SMTP or a mail API, by slug. */
    private const WORDPRESS_SMTP = ['wp-mail-smtp', 'fluent-smtp', 'post-smtp', 'easy-wp-smtp', 'smtp-mailer', 'wp-smtp', 'gmail-smtp', 'mailgun', 'sendgrid-email-delivery-simplified', 'wp-ses', 'mailin'];

    public function __construct(private readonly MailSettings $settings) {}

    /**
     * False when the server has no mail server, so there is no mailbox
     * to send with.
     */
    public function available(): bool
    {
        return $this->settings->isSetUp();
    }

    /**
     * The server applications log in to: the mail server's name, whose
     * certificate their STARTTLS checks.
     */
    public function host(): ?string
    {
        return $this->settings->hostname();
    }

    /**
     * The mail settings of a .env, in the shape problems() reads.
     *
     * @param  array<string, string>  $env
     * @return array{mailer: string, host: string, port: string, username: string, from: string}
     */
    public static function fromEnv(array $env): array
    {
        $get = fn (string $key) => self::clean($env[$key] ?? '');

        return [
            'mailer' => strtolower($get('MAIL_MAILER') !== '' ? $get('MAIL_MAILER') : $get('MAIL_DRIVER')),
            'host' => strtolower($get('MAIL_HOST')),
            'port' => $get('MAIL_PORT'),
            'username' => strtolower($get('MAIL_USERNAME')),
            'from' => strtolower($get('MAIL_FROM_ADDRESS')),
        ];
    }

    /**
     * What keeps an application from sending, as sentences; empty when it
     * can send (or there is no mail server).
     *
     * @param  array{mailer?: mixed, host?: mixed, port?: mixed, username?: mixed, from?: mixed}  $mail
     * @return list<string>
     */
    public function problems(array $mail): array
    {
        if (! $this->available()) {
            return [];
        }
        $mailer = strtolower(self::clean($mail['mailer'] ?? ''));
        $host = strtolower(self::clean($mail['host'] ?? ''));
        $username = strtolower(self::clean($mail['username'] ?? ''));
        $from = strtolower(self::clean($mail['from'] ?? ''));
        $ours = in_array($host, [...self::LOCAL_HOSTS, strtolower((string) $this->host())], true);
        $problems = [];
        if (in_array($mailer, self::NOT_SENDING, true)) {
            $problems[] = __('E-postalar gönderilmiyor, yalnızca uygulamanın günlüğüne yazılıyor (MAIL_MAILER=:mailer).', ['mailer' => $mailer === '' ? 'log' : $mailer]);
        } elseif (in_array($mailer, self::SENDMAIL, true)) {
            $problems[] = __('Bu sunucuda sendmail (PHP mail()) ile gönderim kapalı.');
        } elseif ($mailer === 'smtp' && $ours && $username === '') {
            $problems[] = __('Uygulama :address adresine giriş yapmadan bağlanıyor. Bu sunucu giriş yapılmadan gönderilen e-postayı kabul etmez.', ['address' => ($host ?: '127.0.0.1').':'.(self::clean($mail['port'] ?? '') ?: '25')]);
        }
        if ($from === '' || self::isExample($from)) {
            $problems[] = $from === '' ? __('Gönderen adresi (MAIL_FROM_ADDRESS) boş.') : __('Gönderen adresi :from örnek bir adres.', ['from' => $from]);
        } elseif ($mailer === 'smtp' && $ours && str_contains($username, '@') && $from !== $username) {
            $problems[] = __('Gönderen adresi :from, giriş yapılan posta kutusu :username ile aynı değil. Posta kutusu yalnızca kendi adresiyle gönderebilir.', ['from' => $from, 'username' => $username]);
        }

        return $problems;
    }

    /**
     * The .env values that send as $mailbox: SMTP on port 587 of the mail
     * server with its login, and its address as the sender.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public function values(Mailbox $mailbox, string $password, array $env): array
    {
        $values = [
            'MAIL_MAILER' => 'smtp',
            'MAIL_HOST' => (string) $this->host(),
            'MAIL_PORT' => self::PORT,
            'MAIL_USERNAME' => $mailbox->address(),
            'MAIL_PASSWORD' => $password,
            'MAIL_FROM_ADDRESS' => $mailbox->address(),
        ];
        // Laravel 6 reads MAIL_DRIVER.
        if (array_key_exists('MAIL_DRIVER', $env) && ! array_key_exists('MAIL_MAILER', $env)) {
            $values['MAIL_DRIVER'] = $values['MAIL_MAILER'];
            unset($values['MAIL_MAILER']);
        }
        // STARTTLS on 587, not TLS from the first byte.
        if (array_key_exists('MAIL_SCHEME', $env)) {
            $values['MAIL_SCHEME'] = 'smtp';
        }
        if (array_key_exists('MAIL_ENCRYPTION', $env)) {
            $values['MAIL_ENCRYPTION'] = 'tls';
        }

        return $values;
    }

    /**
     * The mailboxes a site's application may send as: those of its mail
     * domain.
     *
     * @return list<Mailbox>
     */
    public static function mailboxes(Site $site): array
    {
        return $site->mailDomain?->mailboxes()->orderBy('name')->get()->all() ?? [];
    }

    public static function passwordMatches(Mailbox $mailbox, string $password): bool
    {
        return MailPassword::verify($password, (string) $mailbox->password_hash);
    }

    /**
     * $content with $values set, every other line kept.
     *
     * @param  array<string, string>  $values
     */
    public static function write(string $content, array $values): string
    {
        return EnvFile::apply($content, $values + EnvFile::values($content));
    }

    /**
     * The active WordPress plugin that sends mail through SMTP or a mail
     * API, or null while WordPress uses PHP's mail().
     *
     * @param  list<array<string, mixed>>  $plugins
     */
    public static function wordpressSmtpPlugin(array $plugins): ?string
    {
        foreach ($plugins as $plugin) {
            $slug = strtolower((string) ($plugin['name'] ?? ''));
            if (in_array((string) ($plugin['status'] ?? ''), ['active', 'active-network', 'must-use'], true)
                && (in_array($slug, self::WORDPRESS_SMTP, true) || str_contains($slug, 'smtp'))) {
                return (string) (($plugin['title'] ?? '') ?: $slug);
            }
        }

        return null;
    }

    private static function isExample(string $address): bool
    {
        $domain = strtolower(substr(strrchr($address, '@') ?: '@', 1));

        return $domain === ''
            || in_array($domain, ['example.com', 'example.org', 'example.net', 'localhost', 'laravel.com'], true)
            || preg_match('/\.(example|test|local|localhost|invalid)$/', $domain) === 1;
    }

    /**
     * phpdotenv reads null and (null) as null.
     */
    private static function clean(mixed $value): string
    {
        $value = trim((string) $value);

        return in_array(strtolower($value), ['null', '(null)'], true) ? '' : $value;
    }
}
