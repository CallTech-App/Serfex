<?php

namespace App\Livewire\Laravel\Sections;

use App\Models\LaravelApp;
use App\Services\Laravel\Maintenance as MaintenanceMode;
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

    public function down(MaintenanceMode $maintenance): void
    {
        $this->validateForm();
        $this->agentError = $maintenance->down($this->app(), $this->mode, $this->settings());
        if ($this->agentError === null) {
            $this->toast(true);
        }
    }

    public function up(MaintenanceMode $maintenance): void
    {
        $this->agentError = $maintenance->up($this->app());
        if ($this->agentError === null) {
            $this->toast(false);
        }
    }

    /**
     * Saves the address list, page and link while Nginx shows the page.
     */
    public function save(MaintenanceMode $maintenance): void
    {
        $app = $this->app();
        if (! $app->nginxMaintenance()) {
            return;
        }
        $this->validateForm();
        $this->agentError = $maintenance->web($app, ['maintenance_mode' => $this->mode === 'app' ? $app->maintenance_mode : $this->mode] + $this->settings());
        if ($this->agentError === null) {
            $this->dispatch('toast', message: __('Bakım ayarları uygulandı.'));
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
                    $fail(__('En çok :max_allow adres yazılabilir.', ['max_allow' => self::MAX_ALLOW]));
                }
                foreach ($lines as $line) {
                    if (! self::validAddress($line)) {
                        $fail(__(':line bir IP adresi veya ağ (örneğin 10.0.0.0/24) değil.', ['line' => $line]));

                        return;
                    }
                }
            }],
            'html' => [Rule::requiredIf($this->mode === 'custom'), 'nullable', 'string', 'max:200000'],
        ], [
            'secret.regex' => __('Yalnızca harf, rakam, - ve _ kullanın.'),
            'html.required' => __('Ziyaretçilere gösterilecek HTML\'i yazın.'),
        ], ['secret' => __('gizli anahtar'), 'retry' => __('tekrar deneme süresi'), 'html' => 'sayfa']);
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

    private function toast(bool $down): void
    {
        $this->dispatch('toast',
            message: $down ? __('Uygulama bakım modunda.') : __('Uygulama yeniden açıldı.'),
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
