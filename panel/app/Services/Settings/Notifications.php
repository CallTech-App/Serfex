<?php

namespace App\Services\Settings;

use App\Models\Certificate;
use App\Models\Customer;
use App\Models\NotificationEvent;
use App\Models\Setting;
use App\Models\User;
use App\Services\Backups\Backups;
use App\Services\Customers\Quotas;
use App\Services\Customers\UsageNotices;
use App\Services\System\SystemServices;
use App\Services\Updates\Updates;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Araçlar ve Ayarlar → Bildirimler: e-mail and a webhook when something
 * needs the administrators: a certificate, a backup, an update, disk
 * space, a stopped service.
 *
 * servik:notify compares what is wrong now with what the administrators
 * were already told, and sends one message per run with the new problems
 * and the ones that went away. A problem is told once, not every run.
 */
class Notifications
{
    public const KEY = 'notifications';

    /** From this share of a file system's space on, the disk is a problem. */
    public const DISK_LIMIT = 0.9;

    public function __construct(private readonly OutgoingMail $mail) {}

    /**
     * @return array{emails: list<string>, webhook_url: string, webhook_secret: ?string, events: array<string, bool>}
     */
    public function settings(): array
    {
        $saved = (array) Setting::get(self::KEY, []);

        return [
            'emails' => array_values((array) ($saved['emails'] ?? [])),
            'webhook_url' => (string) ($saved['webhook_url'] ?? ''),
            'webhook_secret' => $saved['webhook_secret'] ?? null,
            'events' => array_replace(array_fill_keys(array_keys(NotificationEvent::KINDS), true), (array) ($saved['events'] ?? [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function save(array $values): void
    {
        Setting::put(self::KEY, array_replace($this->settings(), array_intersect_key($values, ['emails' => 1, 'webhook_url' => 1, 'webhook_secret' => 1, 'events' => 1])));
    }

    /**
     * The webhook's signing secret, made the first time it is needed.
     */
    public function secret(): string
    {
        $saved = $this->settings()['webhook_secret'];
        if ($saved !== null) {
            return Crypt::decryptString($saved);
        }
        $secret = Str::random(40);
        $this->save(['webhook_secret' => Crypt::encryptString($secret)]);

        return $secret;
    }

    /**
     * The addresses mail goes to: the saved ones, else the full
     * administrators'.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $emails = $this->settings()['emails'];

        return $emails !== [] ? $emails : User::where('is_admin', true)->whereNull('removed_at')->whereNull('invite_token')
            ->where(fn ($q) => $q->where('role', 'full')->orWhereNull('role'))->orderBy('id')->pluck('email')->all();
    }

    /**
     * What is wrong now: key => [kind, title, detail, url].
     *
     * @return array<string, array{0: string, 1: string, 2: ?string, 3: ?string}>
     */
    public function problems(): array
    {
        $out = [];
        $certificates = Certificate::query()->needsAttention()->with('site')
            ->where(fn ($q) => $q->whereIn('kind', ['panel', 'mail'])->orWhereHas('users')->orWhereNull('not_after'))->get();
        foreach ($certificates as $c) {
            $name = match ($c->kind) {
                'panel' => 'Panel',
                'mail' => __('Posta sunucusu'),
                default => $c->site?->domain ?? implode(', ', (array) $c->domains),
            };
            $out['certificate:'.$c->id] = ['certificate', __(':name sertifikası: :label', ['name' => $name, 'label' => $c->state()->label()]),
                $c->renew_error ?? $c->last_error, $c->site ? route('sites.ssl', $c->site, false) : route('ssl.index', [], false)];
        }
        foreach (app(Backups::class)->problems() as $p) {
            $out['backup:'.substr(sha1($p['text']), 0, 16)] = ['backup', $p['text'], null, route('backups.index', [], false)];
        }
        foreach (app(Updates::class)->notices() as $n) {
            $out['update:'.substr(sha1($n['text']), 0, 16)] = ['update', $n['text'], null, route('settings.updates', [], false)];
        }
        foreach ($this->disks() as $path => $ratio) {
            $out['disk:'.$path] = ['disk', __(':path diski %:ratio dolu', ['path' => $path, 'ratio' => (int) floor($ratio * 100)]), __('Yer açın: eski yedekler, günlükler ve çöp kutuları.'), route('backups.index', [], false)];
        }
        $quotas = app(Quotas::class);
        foreach (Customer::with('package')->get() as $customer) {
            $report = $quotas->report($customer);
            $disk = $report['disk'];
            if ($disk['level'] === 'crit') {
                $out['disk:customer:'.$customer->id] = ['disk', __(':name disk kotasını doldurdu (:text)', ['name' => $customer->displayName(), 'text' => $disk['text']]), null, route('customers.show', $customer, false)];
            }
            $traffic = $report['traffic'];
            if ($traffic['level'] === 'crit') {
                $out['traffic:customer:'.$customer->id] = ['traffic', __(':name aylık trafik sınırını aştı (:text)', ['name' => $customer->displayName(), 'text' => $traffic['text']]),
                    __('Siteler çalışmaya devam ediyor; trafik ay başında sıfırlanır. Paketi yükseltmeyi düşünebilirsiniz.'), route('customers.usage', ['customer' => $customer, 'sekme' => 'trafik'], false)];
            }
        }
        foreach (SystemServices::problems() as $s) {
            $out['service:'.$s['unit']] = ['service', __(':name çalışmıyor', ['name' => $s['name']]), null, route('services.show', $s['unit'], false)];
        }

        return $out;
    }

    /**
     * File systems of the server at or over DISK_LIMIT: path => share used.
     *
     * @return array<string, float>
     */
    public function disks(): array
    {
        $seen = [];
        $out = [];
        foreach ((array) config('servik.disk_paths', ['/', '/home', '/var']) as $path) {
            $total = @disk_total_space($path);
            $free = @disk_free_space($path);
            if (! $total || $free === false || isset($seen[$total.':'.$free])) {
                continue;
            }
            $seen[$total.':'.$free] = true;
            $ratio = 1 - $free / $total;
            if ($ratio >= self::DISK_LIMIT) {
                $out[$path] = $ratio;
            }
        }

        return $out;
    }

    /**
     * One run of servik:notify. Returns how many problems were opened and
     * resolved.
     *
     * @return array{opened: int, resolved: int}
     */
    public function check(): array
    {
        $settings = $this->settings();
        $current = array_filter($this->problems(), fn ($p) => $settings['events'][$p[0]] ?? true);
        $open = NotificationEvent::where('state', 'open')->get()->keyBy('key');

        $opened = [];
        foreach ($current as $key => [$kind, $title, $detail, $url]) {
            if (! $open->has($key)) {
                $opened[] = NotificationEvent::updateOrCreate(['key' => $key], [
                    'kind' => $kind, 'title' => Str::limit($title, 250), 'detail' => $detail, 'url' => $url,
                    'state' => 'open', 'sent' => null, 'error' => null, 'opened_at' => now(), 'resolved_at' => null,
                ]);
            }
        }
        $resolved = [];
        foreach ($open as $key => $event) {
            if (! array_key_exists($key, $current)) {
                $event->update(['state' => 'resolved', 'resolved_at' => now()]);
                $resolved[] = $event;
            }
        }
        if ($opened !== [] || $resolved !== []) {
            $this->deliver($opened, $resolved);
        }
        app(UsageNotices::class)->check();

        return ['opened' => count($opened), 'resolved' => count($resolved)];
    }

    /**
     * Sends a test message on every channel; channel => null or why it failed.
     *
     * @return array<string, ?string>
     */
    public function test(): array
    {
        $out = [];
        if ($this->recipients() !== []) {
            $out['email'] = $this->mail->send($this->recipients(), __('Servik deneme bildirimi'),
                __("Bu bir deneme bildirimidir. Servik'in bildirimleri bu adrese ulaşıyor.")."\n\n".$this->panelUrl()."\n");
        }
        if ($this->settings()['webhook_url'] !== '') {
            $out['webhook'] = $this->post(['event' => 'servik.test', 'problems' => [], 'resolved' => []]);
        }

        return $out;
    }

    /**
     * @param  list<NotificationEvent>  $opened
     * @param  list<NotificationEvent>  $resolved
     */
    private function deliver(array $opened, array $resolved): void
    {
        $settings = $this->settings();
        $sent = [];
        $errors = [];
        $recipients = $this->recipients();
        if ($recipients !== []) {
            $error = $this->mail->send($recipients, $this->subject($opened, $resolved), $this->body($opened, $resolved));
            $sent['email'] = $error === null;
            if ($error !== null) {
                $errors[] = __('E-posta: :error', ['error' => $error]);
            }
        }
        if ($settings['webhook_url'] !== '') {
            $item = fn (NotificationEvent $e) => ['kind' => $e->kind, 'key' => $e->key, 'title' => $e->title, 'detail' => $e->detail,
                'url' => $e->url ? $this->panelUrl().$e->url : null, 'at' => ($e->resolved_at ?? $e->opened_at)->toIso8601String()];
            $error = $this->post(['event' => 'servik.problems', 'problems' => array_map($item, $opened), 'resolved' => array_map($item, $resolved)]);
            $sent['webhook'] = $error === null;
            if ($error !== null) {
                $errors[] = 'Webhook: '.$error;
            }
        }
        foreach ([...$opened, ...$resolved] as $event) {
            $event->update(['sent' => array_replace((array) $event->sent, $sent), 'error' => $errors === [] ? null : implode(' · ', $errors)]);
        }
    }

    /**
     * Posts $payload to the webhook, signed with HMAC-SHA256 of the body
     * in X-Servik-Signature. Returns null, or why it failed.
     *
     * @param  array<string, mixed>  $payload
     */
    private function post(array $payload): ?string
    {
        $body = json_encode(['server' => $this->serverName(), 'sent_at' => now()->toIso8601String(), ...$payload], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        try {
            $response = Http::timeout(10)->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => 'Servik/'.config('servik.version'),
                    'X-Servik-Signature' => 'sha256='.hash_hmac('sha256', $body, $this->secret()),
                ])
                ->withBody($body, 'application/json')
                ->post($this->settings()['webhook_url']);
        } catch (Throwable $e) {
            return Str::limit($e->getMessage(), 200);
        }

        return $response->successful() ? null : __('adres HTTP :status yanıtı verdi', ['status' => $response->status()]);
    }

    /**
     * @param  list<NotificationEvent>  $opened
     * @param  list<NotificationEvent>  $resolved
     */
    private function subject(array $opened, array $resolved): string
    {
        $name = $this->serverName();
        if ($opened === []) {
            return '[Servik] '.$name.': '.(count($resolved) === 1 ? __('düzeldi: :title', ['title' => $resolved[0]->title]) : __(':resolved sorun düzeldi', ['resolved' => count($resolved)]));
        }

        return '[Servik] '.$name.': '.(count($opened) === 1 ? $opened[0]->title : __(':opened sorun', ['opened' => count($opened)]));
    }

    /**
     * @param  list<NotificationEvent>  $opened
     * @param  list<NotificationEvent>  $resolved
     */
    private function body(array $opened, array $resolved): string
    {
        $lines = [];
        if ($opened !== []) {
            $lines[] = __('İlgilenmeniz gerekenler:');
            foreach ($opened as $e) {
                $lines[] = '';
                $lines[] = '- '.$e->kindName().': '.$e->title;
                if ($e->detail) {
                    $lines[] = '  '.Str::limit($e->detail, 300);
                }
                if ($e->url) {
                    $lines[] = '  '.$this->panelUrl().$e->url;
                }
            }
        }
        if ($resolved !== []) {
            $lines[] = '';
            $lines[] = __('Düzelenler:');
            foreach ($resolved as $e) {
                $lines[] = '- '.$e->kindName().': '.$e->title;
            }
        }
        $lines[] = '';
        $lines[] = __('Bildirimleri Araçlar ve Ayarlar → Bildirimler\'den değiştirebilirsiniz: :panel_url:value', ['panel_url' => $this->panelUrl(), 'value' => route('settings.notifications', [], false)]);

        return implode("\n", $lines);
    }

    private function serverName(): string
    {
        return (string) (Setting::get('server.hostname') ?: Setting::get('panel.hostname') ?: gethostname());
    }

    /**
     * https://panel.ornek.com.tr:8443, for links in messages.
     */
    public function panelUrl(): string
    {
        $host = Setting::get('panel.hostname');
        $settings = app(PanelSettings::class);

        return $host ? 'https://'.$host.':'.$settings->port() : rtrim((string) config('app.url'), '/');
    }
}
