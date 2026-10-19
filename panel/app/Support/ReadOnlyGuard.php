<?php

namespace App\Support;

use App\Exceptions\ReadOnlyAccount;
use App\Livewire\Attributes\ForEveryAccount;
use Closure;
use Livewire\Component;
use ReflectionClass;

/**
 * Keeps read-only administrators (role "Salt okunur") from changing
 * anything, in three layers:
 *
 * - Livewire: a method that does more than look (open, close, show,
 *   select, search…) is not called; a toast says why;
 * - the agent: only methods that read reach it;
 * - the database: an INSERT, UPDATE or DELETE outside sign-in
 *   bookkeeping (sessions, cache, the account's own row, the log) fails,
 *   which catches whatever the first two let through.
 */
final class ReadOnlyGuard
{
    /** Livewire methods that only look. */
    private const LOOKING = '/^(\$refresh|\$set|\$toggle|\$sync|\$commit|\$parent|open|close|show|hide|cancel|select|view|search|filter|sort|load|refresh|poll|tab|go|next|previous|setPage|resetPage|gotoPage|preview|watch|expand|collapse|more|back|clear|toggleDetail|switchTab)/';

    /** Agent methods that only read. */
    private const READING = '/(^|\.)(status|list|get|info|logs|log|live|ping|versions|addresses|events|rules|health|usage|runs|timezones|check|scan|search|stat|read|queue|limits|inspect)$/';

    /** Tables the sign-in itself and the log write to. */
    private const BOOKKEEPING = ['sessions', 'cache', 'cache_locks', 'users', 'audit_entries'];

    /**
     * True while the signed-in account is a read-only administrator. Never
     * loads the account: it is asked from inside database queries.
     */
    public static function active(): bool
    {
        if (! app()->bound('auth')) {
            return false;
        }
        $guard = auth()->guard();

        return $guard->hasUser() && (bool) $guard->user()?->isReadOnly();
    }

    /**
     * The Livewire 'call' hook.
     */
    public static function gate(Component $component, string $method, Closure $returnEarly): void
    {
        if (! self::active() || preg_match(self::LOOKING, $method) || $method === '__dispatch'
            || (new ReflectionClass($component))->getAttributes(ForEveryAccount::class) !== []) {
            return;
        }
        $component->dispatch('toast', message: ReadOnlyAccount::MESSAGE);
        $returnEarly();
    }

    /**
     * @throws ReadOnlyAccount
     */
    public static function agent(string $method): void
    {
        if (self::active() && ! preg_match(self::READING, $method)) {
            throw new ReadOnlyAccount;
        }
    }

    /**
     * DB::beforeExecuting.
     *
     * @throws ReadOnlyAccount
     */
    public static function query(string $sql): void
    {
        if (! preg_match('/^\s*(insert\s+into|update|delete\s+from|truncate|alter|drop|create)\s+(?:table\s+)?"?([a-z0-9_]+)"?/i', $sql, $m)) {
            return;
        }
        if (in_array(strtolower($m[2]), self::BOOKKEEPING, true)) {
            return;
        }
        if (self::active()) {
            throw new ReadOnlyAccount;
        }
    }
}
