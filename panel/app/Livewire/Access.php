<?php

namespace App\Livewire;

use App\Livewire\Attributes\ForCustomers;
use App\Livewire\Laravel\GuardsAppCalls;
use App\Models\LaravelApp;
use App\Models\User;
use Livewire\Component;
use ReflectionClass;

/**
 * Who may mount which Livewire component and call which of its methods.
 * Runs before every mount and call (see AppServiceProvider):
 *
 * - administrators may use everything;
 * - customers may use components marked #[ForCustomers] whose feature
 *   their package has, and the Laravel pages when it has the toolkit;
 * - other accounts (Laravel team members) only the Laravel pages.
 *
 * On a Laravel application's page every call is also checked against the
 * caller's role on that application.
 */
final class Access
{
    /** @var array<class-string, ForCustomers|null> */
    private static array $attributes = [];

    public static function authorize(Component $component, ?string $method): void
    {
        $user = auth()->user();
        abort_if($user === null, 403);

        if ($method !== null && in_array(GuardsAppCalls::class, class_uses_recursive($component), true)) {
            $app = LaravelApp::find($component->appId);
            if ($app !== null) {
                abort_unless($user->canOnApp($app, $component->ability($method)), 403);
                $feature = defined($component::class.'::FEATURE') ? $component::FEATURE : null;
                abort_unless($feature === null || $user->mayUseOnApp($app, $feature), 403);
            }

            return;
        }
        abort_unless(self::allows($user, $component::class, $method), 403);
    }

    /**
     * @param  class-string<Component>  $class
     */
    public static function allows(User $user, string $class, ?string $method = null): bool
    {
        if ($user->is_admin) {
            return true;
        }
        $laravel = str_starts_with($class, 'App\\Livewire\\Laravel\\');
        if ($user->isCustomer()) {
            if ($laravel) {
                return $user->allows('laravel');
            }
            $attr = self::attribute($class);

            return $attr !== null
                && ($attr->feature === null || $user->allows($attr->feature))
                && ($method === null || ! in_array($method, $attr->except, true));
        }

        return $laravel && ! ($method !== null && defined($class.'::ADMIN_METHODS') && in_array($method, $class::ADMIN_METHODS, true));
    }

    private static function attribute(string $class): ?ForCustomers
    {
        if (! array_key_exists($class, self::$attributes)) {
            $found = (new ReflectionClass($class))->getAttributes(ForCustomers::class);
            self::$attributes[$class] = $found === [] ? null : $found[0]->newInstance();
        }

        return self::$attributes[$class];
    }
}
