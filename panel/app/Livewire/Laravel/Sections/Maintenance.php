<?php

namespace App\Livewire\Laravel\Sections;

use App\Models\LaravelApp;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Laravel\Processes;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Lazy;

/**
 * Maintenance mode. By default Nginx shows Servik's page (or the
 * customer's own HTML) to everyone but the listed addresses and holders
 * of the bypass link, and the application keeps running; or the
 * application's own php artisan down.
 */
#[Lazy]
class Maintenance extends Section
{
    public const MAX_ALLOW = 64;

    public string $mode = 'servik';

    public string $secret = '';

    public int $retry = 60;

    public string $allow = '';

    public string $html = '';

    public function mount(int $appId): void
    {
        parent::mount($appId);
        $app = $this->app();
        $this->mode = $app->maintenance_mode;
        $this->secret = $app->maintenance_secret ?? self::newSecret();
        $this->retry = $app->maintenance_retry;
        $this->allow = implode("\n", $app->maintenanceAllowList());
        $this->html = (string) $app->maintenance_html;
    }

    /**
     * Adds the address the panel sees this browser come from.
     */
    public function addMine(): void
    {
        $ip = (string) request()->ip();
        if (self::validAddress($ip) && ! in_array($ip, preg_split('/\s+/', $this->allow), true)) {
            $this->allow = trim($this->allow."\n".$ip);
        }
    }

    public function regenerate(): void
    {
        $this->secret = self::newSecret();
    }

    public function down(AgentClient $agent, Processes $processes): void
    {
        $this->validateForm();
        $app = $this->app();
        if ($this->mode === 'app') {
            if ($this->artisan($agent, $app, true)) {
                $app->update(['maintenance_mode' => 'app'] + $this->settings());
                $this->toast(true);
            }

            return;
        }
        if ($this->web($processes, $app, ['maintenance_on' => true, 'maintenance_mode' => $this->mode] + $this->settings())) {
            $this->toast(true);
        }
    }

    public function up(AgentClient $agent, Processes $processes): void
    {
        $app = $this->app();
        $ok = true;
        if ($app->nginxMaintenance()) {
            $ok = $this->web($processes, $app, ['maintenance_on' => false]);
        }
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        if ($ok && ($status['maintenance'] ?? null) !== null) {
            $ok = $this->artisan($agent, $app, false);
        }
        if ($ok) {
            $this->toast(false);
        }
    }

    /**
     * Saves the address list, page and link while Nginx shows the page.
     */
    public function save(Processes $processes): void
    {
        $app = $this->app();
        if (! $app->nginxMaintenance()) {
            return;
        }
        $this->validateForm();
        if ($this->web($processes, $app, ['maintenance_mode' => $this->mode === 'app' ? $app->maintenance_mode : $this->mode] + $this->settings())) {
            $this->dispatch('toast', message: 'Bakım ayarları uygulandı.');
        }
    }

    private function validateForm(): void
    {
        $this->allow = implode("\n", array_values(array_unique(array_filter(array_map('trim', preg_split('/[\s,]+/', $this->allow) ?: [])))));
        $this->validate([
            'mode' => ['required', Rule::in(array_keys(LaravelApp::MAINTENANCE_MODES))],
            'secret' => ['required', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9_-]+$/'],
            'retry' => ['required', 'integer', 'between:0,86400'],
            'allow' => ['nullable', function ($attr, $value, $fail) {
                $lines = array_filter(explode("\n", (string) $value));
                if (count($lines) > self::MAX_ALLOW) {
                    $fail('En çok '.self::MAX_ALLOW.' adres yazılabilir.');
                }
                foreach ($lines as $line) {
                    if (! self::validAddress($line)) {
                        $fail("{$line} bir IP adresi veya ağ (örneğin 10.0.0.0/24) değil.");

                        return;
                    }
                }
            }],
            'html' => [Rule::requiredIf($this->mode === 'custom'), 'nullable', 'string', 'max:200000'],
        ], [
            'secret.regex' => 'Yalnızca harf, rakam, - ve _ kullanın.',
            'html.required' => 'Ziyaretçilere gösterilecek HTML\'i yazın.',
        ], ['secret' => 'gizli anahtar', 'retry' => 'tekrar deneme süresi', 'html' => 'sayfa']);
    }

    public static function validAddress(string $value): bool
    {
        if (str_contains($value, '/')) {
            [$ip, $bits] = explode('/', $value, 2);
            $max = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : 32;

            return filter_var($ip, FILTER_VALIDATE_IP) !== false && ctype_digit($bits) && (int) $bits <= $max;
        }

        return filter_var($value, FILTER_VALIDATE_IP) !== false;
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(): array
    {
        return [
            'maintenance_secret' => $this->secret,
            'maintenance_retry' => $this->retry,
            'maintenance_allow' => $this->allow === '' ? null : $this->allow,
            'maintenance_html' => $this->html === '' ? null : $this->html,
        ];
    }

    /**
     * Changes the application and rewrites its Nginx configuration; puts
     * the old values back when the server refuses.
     *
     * @param  array<string, mixed>  $values
     */
    private function web(Processes $processes, LaravelApp $app, array $values): bool
    {
        $before = $app->only(array_keys($values));
        $app->update($values);
        try {
            $processes->applyWeb($app);
        } catch (AgentException $e) {
            $app->update($before);
            $this->agentError = self::agentMessage($e);

            return false;
        }
        $this->agentError = null;

        return true;
    }

    private function artisan(AgentClient $agent, LaravelApp $app, bool $down): bool
    {
        try {
            $result = $agent->call('laravel.maintenance', $app->agentApp() + ['down' => $down, 'secret' => $this->secret, 'retry' => $this->retry], config('servik.agent.job_timeout'));
        } catch (AgentException $e) {
            $this->agentError = self::agentMessage($e);

            return false;
        }
        if (($result['exit_code'] ?? 1) !== 0) {
            $this->agentError = 'Komut başarısız oldu: '.trim((string) ($result['output'] ?? ''));

            return false;
        }
        $this->agentError = null;

        return true;
    }

    private function toast(bool $down): void
    {
        $this->dispatch('toast',
            message: $down ? 'Uygulama bakım modunda.' : 'Uygulama yeniden açıldı.',
            source: $this->getId(),
            undo: ['method' => $down ? 'up' : 'down', 'args' => []],
        );
    }

    public function render(): View
    {
        $app = $this->app();
        $status = $this->agent('laravel.status', $app->agentApp(), []);
        $site = $app->site;
        $artisanDown = $status['maintenance'] ?? null;
        $down = $app->nginxMaintenance() || $artisanDown !== null;
        // While down with artisan, the link is the one it was put down with.
        $secret = $app->nginxMaintenance() ? (string) $app->maintenance_secret : ($artisanDown['secret'] ?? $this->secret);

        return view('livewire.laravel.sections.maintenance', [
            'app' => $app,
            'down' => $down,
            'nginx' => $app->nginxMaintenance(),
            'operate' => auth()->user()->canOnApp($app, 'operate'),
            'link' => $secret === '' ? null : ($site->hasHttps() ? 'https://' : 'http://').$site->domain.'/'.$secret,
            'visitor' => request()->ip(),
        ]);
    }

    private static function newSecret(): string
    {
        return Str::lower(Str::random(24));
    }
}
