<?php

namespace App\Services\Audit;

use App\Livewire\Access;
use App\Models\AuditEntry;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Models\User;
use App\Support\Impersonation;
use Closure;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;
use ReflectionClass;
use Throwable;

use function Livewire\store;
use function Livewire\wrap;

/**
 * İşlem günlüğü: every action taken through the panel, by whom.
 *
 * Panel pages already tell the person what they did in a toast ("DNS
 * kaydı silindi."), with Undo when it can be undone. Every toast a
 * Livewire call sends becomes an entry, so new pages are logged without
 * extra code; the API, Servik AI's automatic actions, sign-ins and the
 * few plain HTTP actions record themselves.
 *
 * Undo from the log opens the page the action was taken on and calls the
 * same undo method there, with the same checks as the toast's button.
 */
final class AuditLog
{
    /** The query parameter that asks a page to undo an entry. */
    public const UNDO_PARAM = 'geri-al';

    /** Sections by Livewire namespace. */
    private const SECTIONS = [
        'Sites' => 'Siteler', 'Dns' => 'Alan Adları ve DNS', 'Mail' => 'E-posta', 'Databases' => 'Veritabanları',
        'Ssl' => 'SSL Sertifikaları', 'Laravel' => 'Laravel', 'Customers' => 'Müşteriler ve Paketler', 'Backups' => 'Yedekler',
        'Security' => 'Güvenlik', 'Services' => 'Hizmetler', 'Components' => 'Yazılım Yönetimi', 'Monitor' => 'Canlı İzleme',
        'Ai' => 'Servik AI', 'Settings' => 'Araçlar ve Ayarlar', 'Account' => 'Hesap güvenliği',
    ];

    /**
     * Records an action. $user defaults to the signed-in account; while an
     * administrator works as a customer, the administrator is named.
     *
     * @param  array<string, mixed>  $extra  actor, section, url, component, undo, customer_id
     */
    public static function record(string $message, ?User $user = null, string $via = 'panel', array $extra = []): ?AuditEntry
    {
        try {
            $user ??= auth()->user();
            $actor = $user?->name ?? __('Sistem');
            $admin = Impersonation::administrator();
            if ($admin !== null && $user?->isCustomer()) {
                $actor = __(':name (:name2 olarak)', ['name' => $admin->name, 'name2' => $user->name]);
            }
            $actor = $extra['actor'] ?? $actor;

            return AuditEntry::create([
                'user_id' => $user?->id,
                'actor' => Str::limit($actor, 117),
                'via' => $via,
                'customer_id' => $extra['customer_id'] ?? ($user?->isCustomer() ? $user->customer_id : null),
                'section' => isset($extra['section']) ? Str::limit((string) $extra['section'], 117) : null,
                'message' => Str::limit($message, 2000),
                'url' => $extra['url'] ?? null,
                'component' => $extra['component'] ?? null,
                'undo' => $extra['undo'] ?? null,
                'ip' => app()->runningInConsole() ? null : request()->ip(),
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            // The log never stops the action it describes.
            report($e);

            return null;
        }
    }

    /**
     * The 'call' hook: after a Livewire method ran, logs the toasts it
     * sent and marks an entry undone when the call was its undo.
     */
    public static function watch(Component $component, string $method, array $params): ?Closure
    {
        if (str_starts_with($method, '$') || $method === '__dispatch' || auth()->user() === null) {
            return null;
        }
        $before = count(store($component)->get('dispatched', []));
        $flash = session()->get('toast');

        return function ($return) use ($component, $method, $params, $before, $flash) {
            $messages = [];
            foreach (array_slice(store($component)->get('dispatched', []), $before) as $event) {
                $e = $event->serialize();
                if ($e['name'] === 'toast' && is_string($e['params']['message'] ?? null)) {
                    $messages[] = [$e['params']['message'], $e['params']['undo'] ?? null];
                }
            }
            $flashed = session()->get('toast');
            if (is_string($flashed) && $flashed !== $flash) {
                $messages[] = [$flashed, null];
            }
            if ($messages !== []) {
                self::fromPage($component, $method, $params, $messages);
            }

            return $return;
        };
    }

    /**
     * @param  list<array{0: string, 1: array<string, mixed>|null}>  $messages
     */
    private static function fromPage(Component $component, string $method, array $params, array $messages): void
    {
        $user = auth()->user();
        $class = $component::class;
        // The toast's own Undo button: the entry it undoes is done.
        AuditEntry::where('user_id', $user->id)->where('component', $class)->whereNull('undone_at')
            ->where('created_at', '>', now()->subDay())->latest('id')->limit(20)->get()
            ->first(fn (AuditEntry $e) => ($e->undo['method'] ?? null) === $method && array_values($e->undo['args'] ?? []) === array_values($params))
            ?->update(['undone_at' => now()]);

        $url = self::pageUrl();
        foreach ($messages as [$message, $undo]) {
            self::record($message, $user, 'panel', [
                'section' => self::section($component),
                'url' => $url,
                'component' => $class,
                'undo' => is_array($undo) && is_string($undo['method'] ?? null) ? ['method' => $undo['method'], 'args' => array_values((array) ($undo['args'] ?? []))] : null,
            ]);
        }
    }

    /**
     * The page the person was on: the address of a full page load, or the
     * page a Livewire update came from.
     */
    private static function pageUrl(): ?string
    {
        $request = request();
        $url = $request->hasHeader('X-Livewire') || str_contains($request->path(), 'livewire') ? $request->header('Referer') : $request->fullUrl();
        if (! is_string($url) || $url === '') {
            return null;
        }
        $parts = parse_url($url);
        $path = ($parts['path'] ?? '/').(isset($parts['query']) ? '?'.$parts['query'] : '');

        // Only the path: the log must not send anyone to another host.
        return Str::limit(preg_replace('/([?&])'.self::UNDO_PARAM.'=\d+&?/', '$1', $path), 497, '');
    }

    /**
     * "Siteler · kahve.com.tr", "Alan Adları ve DNS", "SSL ayarları".
     */
    public static function section(Component $component): string
    {
        $class = $component::class;
        $title = (new ReflectionClass($class))->getAttributes(Title::class);
        $name = $title !== [] ? Str::before((string) $title[0]->newInstance()->content, ' · ') : null;
        $name ??= self::SECTIONS[Str::of($class)->after('App\\Livewire\\')->before('\\')->toString()] ?? class_basename($class);
        $domain = match (true) {
            property_exists($component, 'siteId') && is_int($component->siteId ?? null) => Site::whereKey($component->siteId)->value('domain'),
            property_exists($component, 'appId') && is_int($component->appId ?? null) => LaravelApp::with('site')->find($component->appId)?->site?->domain,
            default => null,
        };

        return $domain ? $name.' · '.$domain : $name;
    }

    /**
     * The 'mount' hook (it runs after the component's own mount()): a page
     * opened from the log's Undo link undoes the entry, then reloads
     * itself without the link.
     */
    public static function undoOnMount(Component $component): void
    {
        $id = request()->query(self::UNDO_PARAM);
        $user = auth()->user();
        if (! is_string($id) || ! ctype_digit($id) || request()->hasHeader('X-Livewire') || $user === null) {
            return;
        }
        $entry = AuditEntry::find((int) $id);
        if ($entry === null || $entry->component !== $component::class) {
            return;
        }
        if ($user->isReadOnly() || ! $entry->undoable() || ! ($entry->user_id === $user->id || $user->isFullAdmin())) {
            session()->flash('toast', __('Bu işlem artık geri alınamıyor.'));
            $component->redirect(self::withoutUndo(), navigate: true);

            return;
        }
        $method = $entry->undo['method'];
        Access::authorize($component, $method);
        $before = count(store($component)->get('dispatched', []));
        wrap($component)->{$method}(...$entry->undo['args'] ?? []);
        $entry->update(['undone_at' => now()]);
        $said = collect(array_slice(store($component)->get('dispatched', []), $before))
            ->map(fn ($e) => $e->serialize())->firstWhere('name', 'toast')['params']['message'] ?? __('Geri alındı.');
        self::record($said, $user, 'panel', ['section' => self::section($component), 'url' => self::withoutUndo(), 'component' => $component::class]);
        session()->flash('toast', $said);
        $component->redirect(self::withoutUndo(), navigate: true);
    }

    private static function withoutUndo(): string
    {
        $query = request()->except(self::UNDO_PARAM);

        return '/'.ltrim(request()->path(), '/').($query === [] ? '' : '?'.http_build_query($query));
    }
}
