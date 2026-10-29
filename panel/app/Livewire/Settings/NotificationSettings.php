<?php

namespace App\Livewire\Settings;

use App\Models\NotificationEvent;
use App\Services\Settings\Notifications;
use App\Services\Settings\OutgoingMail;
use App\Support\Locales;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Araçlar ve Ayarlar → Bildirimler: who hears about which problems, by
 * e-mail and webhook, and how the panel sends its mail.
 */
#[Layout('components.layouts.app')]
#[Title('Bildirimler · Serfex')]
class NotificationSettings extends Component
{
    public string $emails = '';

    /** @var array<string, bool> */
    public array $events = [];

    public string $webhookUrl = '';

    /** Language of the webhook's titles; '' is the server's default. */
    public string $webhookLocale = '';

    public bool $showSecret = false;

    public string $mode = 'server';

    public string $host = '';

    public string $port = '587';

    public string $encryption = 'starttls';

    public string $username = '';

    public string $password = '';

    public string $fromAddress = '';

    /** @var array<string, ?string>|null the last test: channel => error */
    public ?array $tested = null;

    public function mount(Notifications $notifications, OutgoingMail $mail): void
    {
        $s = $notifications->settings();
        $this->emails = implode(', ', $s['emails']);
        $this->events = $s['events'];
        $this->webhookUrl = $s['webhook_url'];
        $this->webhookLocale = (string) $s['webhook_locale'];
        $m = $mail->settings();
        $this->mode = $m['mode'];
        $this->host = (string) $m['host'];
        $this->port = (string) $m['port'];
        $this->encryption = $m['encryption'];
        $this->username = (string) $m['username'];
        $this->fromAddress = (string) $m['from_address'];
        $this->password = '';
    }

    public function save(Notifications $notifications): void
    {
        $emails = $this->emailList();
        $this->webhookUrl = trim($this->webhookUrl);
        $this->validate([
            'webhookUrl' => ['nullable', 'url:https,http', 'max:500'],
            'webhookLocale' => ['nullable', Rule::in(array_keys(Locales::all()))],
        ], [], ['webhookUrl' => __('webhook adresi'), 'webhookLocale' => __('webhook dili')]);
        foreach ($emails as $email) {
            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $this->addError('emails', __(':email geçerli bir e-posta adresi değil.', ['email' => $email]));

                return;
            }
        }
        if (count($emails) > 10) {
            $this->addError('emails', __('En fazla 10 adres girin.'));

            return;
        }
        $previous = $notifications->settings();
        $notifications->save([
            'emails' => $emails,
            'events' => array_map('boolval', array_intersect_key($this->events, NotificationEvent::KINDS)),
            'webhook_url' => $this->webhookUrl,
            'webhook_locale' => $this->webhookLocale ?: null,
        ]);
        $this->emails = implode(', ', $emails);
        $this->dispatch('toast', message: __('Bildirim ayarları kaydedildi.'), source: $this->getId(),
            undo: ['method' => 'undoSave', 'args' => [['emails' => $previous['emails'], 'events' => $previous['events'], 'webhook_url' => $previous['webhook_url'], 'webhook_locale' => $previous['webhook_locale']]]]);
    }

    /**
     * @param  array{emails: list<string>, events: array<string, bool>, webhook_url: string, webhook_locale?: ?string}  $previous
     */
    public function undoSave(Notifications $notifications, OutgoingMail $mail, array $previous): void
    {
        $emails = array_values(array_filter((array) ($previous['emails'] ?? []), fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL)));
        $url = (string) ($previous['webhook_url'] ?? '');
        $notifications->save([
            'emails' => array_slice($emails, 0, 10),
            'events' => array_map('boolval', array_intersect_key((array) ($previous['events'] ?? []), NotificationEvent::KINDS)),
            'webhook_url' => $url === '' || filter_var($url, FILTER_VALIDATE_URL) ? $url : '',
            'webhook_locale' => Locales::supports($previous['webhook_locale'] ?? null) ? $previous['webhook_locale'] : null,
        ]);
        $this->mount($notifications, $mail);
        $this->dispatch('toast', message: __('Bildirim ayarlarındaki değişiklik geri alındı.'));
    }

    public function newSecret(Notifications $notifications): void
    {
        $previous = $notifications->settings()['webhook_secret'];
        $notifications->save(['webhook_secret' => Crypt::encryptString(Str::random(40))]);
        $this->showSecret = true;
        $this->dispatch('toast', message: __('Webhook imza anahtarı yenilendi.'), source: $this->getId(), undo: ['method' => 'undoSecret', 'args' => [$previous]]);
    }

    public function undoSecret(Notifications $notifications, ?string $previous): void
    {
        if ($previous !== null) {
            try {
                Crypt::decryptString($previous);
            } catch (\Throwable) {
                return;
            }
        }
        $notifications->save(['webhook_secret' => $previous]);
        $this->dispatch('toast', message: __('Önceki imza anahtarı geri geldi.'));
    }

    public function saveMail(OutgoingMail $mail): void
    {
        $this->host = trim($this->host);
        $this->username = trim($this->username);
        $this->fromAddress = trim($this->fromAddress);
        $this->validate([
            'mode' => ['required', Rule::in(['server', 'smtp'])],
            'host' => ['exclude_unless:mode,smtp', 'required', 'max:253', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['exclude_unless:mode,smtp', 'required', 'integer', 'min:1', 'max:65535'],
            'encryption' => ['exclude_unless:mode,smtp', 'required', Rule::in(array_keys(OutgoingMail::ENCRYPTION))],
            'username' => ['nullable', 'string', 'max:254'],
            'password' => ['nullable', 'string', 'max:254'],
            'fromAddress' => ['nullable', 'email:rfc', 'max:254'],
        ], ['host.regex' => __('Sunucu adını ya da IP adresini girin, örneğin smtp.ornek.com.tr.')],
            ['host' => __('SMTP sunucusu'), 'port' => 'port', 'encryption' => __('şifreleme'), 'username' => __('kullanıcı adı'), 'password' => 'parola', 'fromAddress' => __('gönderen adresi')]);
        $previous = $mail->settings();
        $mail->save([
            'mode' => $this->mode,
            'host' => $this->host,
            'port' => (int) $this->port,
            'encryption' => $this->encryption,
            'username' => $this->username,
            'password' => $this->password === '' ? null : $this->password,
            'from_address' => $this->fromAddress,
        ]);
        $this->password = '';
        $this->dispatch('toast', message: __('E-posta gönderimi kaydedildi.'), source: $this->getId(), undo: ['method' => 'undoMail', 'args' => [array_diff_key($previous, ['password' => 1])]]);
    }

    /**
     * Undo keeps the password as it is: it never travels to the browser.
     *
     * @param  array<string, mixed>  $previous
     */
    public function undoMail(Notifications $notifications, OutgoingMail $mail, array $previous): void
    {
        $mail->save([
            'mode' => in_array($previous['mode'] ?? null, ['server', 'smtp'], true) ? $previous['mode'] : 'server',
            'host' => preg_match('/^[A-Za-z0-9.-]{0,253}$/', (string) ($previous['host'] ?? '')) ? (string) $previous['host'] : '',
            'port' => max(1, min(65535, (int) ($previous['port'] ?? 587))),
            'encryption' => isset(OutgoingMail::ENCRYPTION[$previous['encryption'] ?? '']) ? $previous['encryption'] : 'starttls',
            'username' => Str::limit((string) ($previous['username'] ?? ''), 254, ''),
            'from_address' => filter_var($previous['from_address'] ?? '', FILTER_VALIDATE_EMAIL) ? $previous['from_address'] : '',
        ]);
        $this->mount($notifications, $mail);
        $this->dispatch('toast', message: __('E-posta gönderimindeki değişiklik geri alındı.'));
    }

    public function test(Notifications $notifications): void
    {
        $this->tested = $notifications->test();
        if ($this->tested === []) {
            $this->dispatch('toast', message: __('Gönderilecek bir adres ya da webhook yok.'));
        }
    }

    public function render(Notifications $notifications, OutgoingMail $mail): View
    {
        return view('livewire.settings.notifications', [
            'recent' => NotificationEvent::latest('updated_at')->limit(15)->get(),
            'defaultRecipients' => $this->emails === '' ? $notifications->recipients() : [],
            'secret' => $this->showSecret ? $notifications->secret() : null,
            'hasPassword' => $mail->settings()['password'] !== null,
            'mailProblem' => $mail->problem(),
            'deliveryWarning' => $mail->deliveryWarning(),
            'fromDefault' => $mail->fromAddress(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function emailList(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($e) => mb_strtolower(trim($e)), preg_split('/[\s,;]+/', $this->emails) ?: []))));
    }
}
