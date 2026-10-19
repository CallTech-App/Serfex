<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A panel account. Administrators run the whole server; other accounts
 * work only on the Laravel applications they were added to, with the role
 * they were given there.
 */
#[Fillable(['name', 'email', 'password', 'is_admin', 'invite_token', 'invited_at'])]
#[Hidden(['password', 'remember_token', 'invite_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /** How much each role may do, and what each ability needs. */
    private const ROLE_RANK = ['viewer' => 1, 'developer' => 2, 'owner' => 3];

    private const ABILITY_RANK = ['view' => 1, 'operate' => 2, 'manage' => 3];

    protected $attributes = [
        'is_admin' => true,
    ];

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
        ];
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
     * True while the account waits for its invitation to be accepted.
     */
    public function isInvited(): bool
    {
        return $this->invite_token !== null;
    }
}
