<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\Locales;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A panel account. Administrators run the whole server; a customer's
 * login sees the customer's own sites, mail, DNS, certificates and Laravel
 * applications within their package; other accounts work only on the
 * Laravel applications they were added to, with the role they were given
 * there.
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'customer_id', 'invite_token', 'invited_at', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at', 'role', 'last_login_at', 'last_login_ip', 'removed_at', 'locale'])]
#[Hidden(['password', 'remember_token', 'invite_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** How much each role may do, and what each ability needs. */
    private const ROLE_RANK = ['viewer' => 1, 'developer' => 2, 'owner' => 3];

    private const ABILITY_RANK = ['view' => 1, 'operate' => 2, 'manage' => 3];

    /** Administrator roles: key => [name, what it may do]. */
    public const ROLES = [
        'full' => ['Tam yönetici', 'Her şey; Araçlar ve Ayarlar, yöneticiler ve API anahtarları dahil.'],
        'support' => ['Destek', 'Siteler, müşteriler ve sunucudaki her iş; Araçlar ve Ayarlar hariç.'],
        'readonly' => ['Salt okunur', 'Her sayfayı görür, hiçbir şeyi değiştiremez; Servik AI\'yı kullanamaz.'],
    ];

    protected $attributes = [
        'is_admin' => true,
    ];

    /**
     * The language this person reads the panel and their mail in.
     */
    public function preferredLocale(): string
    {
        return Locales::forUser($this);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
            'invited_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'last_login_at' => 'datetime',
            'removed_at' => 'datetime',
        ];
    }

    /**
     * The customer this login belongs to, if any.
     *
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function isCustomer(): bool
    {
        return ! $this->is_admin && $this->customer_id !== null;
    }

    /**
     * full, support or readonly for administrators; null for everyone else.
     */
    public function adminRole(): ?string
    {
        return $this->is_admin ? ($this->role ?? 'full') : null;
    }

    /**
     * An administrator who may also change the panel itself: settings,
     * administrators, API keys and updates.
     */
    public function isFullAdmin(): bool
    {
        return $this->adminRole() === 'full';
    }

    /**
     * An administrator who may look at everything and change nothing.
     */
    public function isReadOnly(): bool
    {
        return $this->adminRole() === 'readonly';
    }

    public function roleName(): string
    {
        return isset(self::ROLES[$this->adminRole()]) ? __(self::ROLES[$this->adminRole()][0]) : ($this->isCustomer() ? __('Müşteri') : __('Laravel ekibi'));
    }

    /**
     * Whether the account may use a package feature (see Package::FEATURES)
     * at all: administrators always, customers when their package has it,
     * others never.
     */
    public function allows(string $feature): bool
    {
        if ($this->is_admin) {
            return true;
        }

        return (bool) $this->customer?->package?->allows($feature);
    }

    /**
     * True when the account may see $site.
     */
    public function canSeeSite(Site $site): bool
    {
        return $this->is_admin || ($this->customer_id !== null && $site->systemUser?->customer_id === $this->customer_id);
    }

    /**
     * @return HasMany<LaravelAppMember, $this>
     */
    public function laravelMemberships(): HasMany
    {
        return $this->hasMany(LaravelAppMember::class);
    }

    /**
     * owner, developer, viewer or null. Administrators own every
     * application; a preview environment has the members of the
     * application it previews.
     */
    public function laravelRole(LaravelApp $app): ?string
    {
        if ($this->is_admin) {
            return 'owner';
        }
        // A customer owns the applications on their own sites while their
        // package has the Laravel toolkit.
        if ($this->customer_id !== null && $this->allows('laravel')) {
            $root = $app->preview_of_id !== null ? LaravelApp::with('site.systemUser')->find($app->preview_of_id) : $app;
            if ($root?->site !== null && $this->canSeeSite($root->site)) {
                return 'owner';
            }
        }

        return $this->laravelMemberships->firstWhere('laravel_app_id', $app->preview_of_id ?? $app->id)?->role;
    }

    /**
     * view (see the application), operate (deploy, run, change settings)
     * or manage (people, tokens, restores, removal).
     */
    public function canOnApp(LaravelApp $app, string $ability): bool
    {
        return (self::ROLE_RANK[$this->laravelRole($app)] ?? 0) >= self::ABILITY_RANK[$ability];
    }

    /**
     * Whether the account may use a package feature on $app: the feature
     * of the package of the customer who owns the application's site.
     * Administrators always may; applications without an owner have every
     * feature.
     */
    public function mayUseOnApp(LaravelApp $app, string $feature): bool
    {
        if ($this->is_admin) {
            return true;
        }
        $app->loadMissing('site.systemUser.customer.package');
        $package = $app->site?->customer()?->package;

        return $package === null || $package->allows($feature);
    }

    /**
     * True once the account confirmed an authenticator app: signing in
     * then asks for its code too.
     */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    /**
     * True while the account waits for its invitation to be accepted.
     */
    public function isInvited(): bool
    {
        return $this->invite_token !== null;
    }
}
