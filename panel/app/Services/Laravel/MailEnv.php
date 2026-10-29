<?php

namespace App\Services\Laravel;

use App\Models\LaravelApp;
use App\Models\LaravelEnvVersion;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Mail\MailSenders;
use App\Services\Mail\MailSettings;
use Illuminate\Support\Facades\Log;

/**
 * Whether a Laravel application can send mail from this server, and the
 * .env values that make it so.
 *
 * Since 0.1.49 a site may send only as its own domains: through sendmail
 * as the site's account, or with a mailbox login on port 587. SMTP to
 * 127.0.0.1:25 without a login is refused for hosted domains, because
 * Postfix cannot tell which site connected. Laravel's .env.example
 * leaves MAIL_MAILER=log (or smtp to 127.0.0.1:2525) and
 * hello@example.com, so a new application sends nothing.
 *
 * An application that uses a real outside SMTP server or a mail API
 * (SES, Postmark, Mailgun…) is left alone; only an example sender
 * address is changed there.
 */
class MailEnv
{
    public const MAILER = 'sendmail';

    /** Mailers that do not send: the message goes to the log or memory. */
    private const NOT_SENDING = ['', 'log', 'array'];

    private const LOCAL_HOSTS = ['', '127.0.0.1', 'localhost', '::1', '[::1]', '0.0.0.0', 'mailpit', 'mailhog'];

    public function __construct(private readonly MailSettings $settings, private readonly Environments $environments) {}

    /**
     * False when the server has no mail server, so there is nothing that
     * could send.
     */
    public function available(): bool
    {
        return $this->settings->isSetUp();
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
            'username' => $get('MAIL_USERNAME'),
            'from' => strtolower($get('MAIL_FROM_ADDRESS')),
        ];
    }

    /**
     * What is wrong with an application's mail settings, as sentences;
     * empty when it can send (or there is no mail server to send with).
     *
     * @param  array{mailer?: string, host?: string, port?: string, username?: string, from?: string}  $mail
     * @return list<string>
     */
    public function problems(Site $site, array $mail): array
    {
        if (! $this->available()) {
            return [];
        }
        $mailer = strtolower(self::clean($mail['mailer'] ?? ''));
        $from = strtolower(self::clean($mail['from'] ?? ''));
        $problems = [];
        if (in_array($mailer, self::NOT_SENDING, true)) {
            $problems[] = __('E-postalar gönderilmiyor, yalnızca uygulamanın günlüğüne yazılıyor (MAIL_MAILER=:mailer).', ['mailer' => $mailer === '' ? 'log' : $mailer]);
        } elseif ($this->localSmtp($mail)) {
            $problems[] = __('Uygulama :address adresine giriş yapmadan bağlanıyor. Bu sunucu bu yoldan barındırılan alan adlarıyla gönderilen e-postayı reddeder.', ['address' => (self::clean($mail['host'] ?? '') ?: '127.0.0.1').':'.(self::clean($mail['port'] ?? '') ?: '25')]);
        }
        $sendmail = $mailer === self::MAILER || $this->fixesMailer($mail);
        if ($from === '' || self::isExample($from)) {
            $problems[] = $from === '' ? __('Gönderen adresi (MAIL_FROM_ADDRESS) boş.') : __('Gönderen adresi :from örnek bir adres.', ['from' => $from]);
        } elseif ($sendmail && ! $this->ownsAddress($site, $from)) {
            $problems[] = __('Gönderen adresi :from bu sitenin alan adlarından biri değil. Sunucu bu siteden yalnızca kendi alan adlarıyla gönderime izin verir.', ['from' => $from]);
        }

        return $problems;
    }

    /**
     * The .env values that fix problems(): sendmail as the mailer when it
     * does not send or uses local SMTP without a login, and
     * noreply@<site> as the sender when it is an example or not the
     * site's. Empty when nothing needs to change.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    public function fix(Site $site, array $env): array
    {
        if (! $this->available()) {
            return [];
        }
        $mail = self::fromEnv($env);
        $values = [];
        $sendmail = $mail['mailer'] === self::MAILER;
        if ($this->fixesMailer($mail)) {
            // Laravel 6 and older read MAIL_DRIVER.
            $values[array_key_exists('MAIL_MAILER', $env) || ! array_key_exists('MAIL_DRIVER', $env) ? 'MAIL_MAILER' : 'MAIL_DRIVER'] = self::MAILER;
            $sendmail = true;
        }
        $from = $mail['from'];
        if ($from === '' || self::isExample($from) || ($sendmail && ! $this->ownsAddress($site, $from))) {
            $values['MAIL_FROM_ADDRESS'] = self::sender($site);
        }

        return $values;
    }

    /**
     * Writes fix() into the application's .env, keeping the file as it
     * was in the history. Returns the history entry, or null when nothing
     * changed.
     *
     * @throws AgentException
     */
    public function apply(LaravelApp $app, ?string $by): ?LaravelEnvVersion
    {
        $before = (string) ($this->environments->read($app)['env'] ?? '');
        $values = $this->fix($app->site, EnvFile::values($before));
        if ($values === []) {
            return null;
        }

        return $this->environments->save($app, $before, self::write($before, $values), $by)['version'];
    }

    /**
     * After an install: the new application sends mail from the start. A
     * failure only leaves the warning on its page.
     */
    public function setUp(LaravelApp $app): void
    {
        if ($app->isPreview() || ! $this->available()) {
            return;
        }
        try {
            $this->apply($app, 'Serfex');
        } catch (AgentException $e) {
            Log::warning("Mail settings of {$app->site->domain} were not written: {$e->getMessage()}");
        }
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
     * The address a site's application sends as by default.
     */
    public static function sender(Site $site): string
    {
        return 'noreply@'.strtolower($site->domain);
    }

    /**
     * @param  array<string, string>  $mail
     */
    private function fixesMailer(array $mail): bool
    {
        return in_array(strtolower(self::clean($mail['mailer'] ?? '')), self::NOT_SENDING, true) || $this->localSmtp($mail);
    }

    /**
     * SMTP to this server without a login: refused for hosted domains on
     * port 25, and nothing listens on Mailpit's 1025 or Laravel's 2525.
     *
     * @param  array<string, string>  $mail
     */
    private function localSmtp(array $mail): bool
    {
        return strtolower(self::clean($mail['mailer'] ?? '')) === 'smtp'
            && in_array(strtolower(self::clean($mail['host'] ?? '')), self::LOCAL_HOSTS, true)
            && self::clean($mail['username'] ?? '') === '';
    }

    private function ownsAddress(Site $site, string $address): bool
    {
        $domain = strtolower(substr(strrchr($address, '@') ?: '', 1));
        $own = $site->systemUser !== null ? MailSenders::domainsOf($site->systemUser) : $site->hostnames();

        return $domain !== '' && in_array($domain, array_map('strtolower', $own), true);
    }

    private static function isExample(string $address): bool
    {
        $domain = strtolower(substr(strrchr($address, '@') ?: '@', 1));

        return $domain === '' || ! str_contains($address, '@')
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
