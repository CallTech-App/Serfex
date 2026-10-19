<?php

namespace App\Models;

use App\Enums\CertificateStatus;
use App\Enums\SiteStatus;
use App\Support\CertificateState;
use Database\Factories\SiteFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['system_user_id', 'domain', 'aliases', 'php_version', 'document_root', 'web_root', 'certificate_id', 'https_redirect', 'status', 'last_error', 'provisioned_at'])]
class Site extends Model
{
    /** @use HasFactory<SiteFactory> */
    use HasFactory;

    protected $attributes = [
        'aliases' => '[]',
        'status' => 'provisioning',
        'https_redirect' => true,
    ];

    protected function casts(): array
    {
        return [
            'aliases' => 'array',
            'status' => SiteStatus::class,
            'provisioned_at' => 'datetime',
            'https_redirect' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SystemUser, $this>
     */
    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * The customer who owns the site, through its account.
     */
    public function customer(): ?Customer
    {
        return $this->systemUser?->customer;
    }

    /**
     * True while the owner's account is suspended (or being deleted): the
     * site shows the suspension page.
     */
    public function isSuspended(): bool
    {
        return (bool) $this->customer()?->isSuspended();
    }

    /**
     * Sites the user may see: all of them for an administrator, their own
     * for a customer, none for anyone else.
     *
     * @param  Builder<Site>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->is_admin) {
            return;
        }
        if ($user->customer_id === null) {
            $query->whereRaw('false');

            return;
        }
        $query->whereHas('systemUser', fn ($u) => $u->where('customer_id', $user->customer_id));
    }

    /**
     * The certificate the site serves HTTPS with.
     *
     * @return BelongsTo<Certificate, $this>
     */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /**
     * Every certificate obtained or uploaded for the site, in use or not.
     *
     * @return HasMany<Certificate, $this>
     */
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * The Laravel application on the site, if any.
     *
     * @return HasOne<LaravelApp, $this>
     */
    public function laravelApp(): HasOne
    {
        return $this->hasOne(LaravelApp::class);
    }

    /**
     * The preview environment this site was made for, if any.
     *
     * @return HasOne<LaravelPreview, $this>
     */
    public function preview(): HasOne
    {
        return $this->hasOne(LaravelPreview::class);
    }

    /**
     * Temporary resource limits put on the site, past and present.
     *
     * @return HasMany<ResourceLimit, $this>
     */
    public function resourceLimits(): HasMany
    {
        return $this->hasMany(ResourceLimit::class);
    }

    /**
     * The limit in force now, if any.
     */
    public function activeLimit(): ?ResourceLimit
    {
        return $this->resourceLimits()->active()->latest('id')->first();
    }

    /**
     * Host names the site answers to.
     *
     * @return list<string>
     */
    public function hostnames(): array
    {
        return [$this->domain, ...array_values($this->aliases ?? [])];
    }

    /**
     * True when the site is served over HTTPS.
     */
    public function hasHttps(): bool
    {
        return $this->certificate !== null && $this->certificate->isInstalled();
    }

    /**
     * A Let's Encrypt request that has not produced the site's certificate
     * yet: being issued, waiting for DNS or failed.
     */
    public function pendingCertificate(): ?Certificate
    {
        return $this->certificates
            ->where('id', '!=', $this->certificate_id)
            ->where('status', '!=', CertificateStatus::Active)
            ->sortByDesc('id')
            ->first();
    }

    /**
     * The site's HTTPS state for lists: its certificate's, or the pending
     * request's, or "no certificate".
     */
    public function sslState(): CertificateState
    {
        if ($this->certificate !== null) {
            return $this->certificate->state();
        }

        return $this->pendingCertificate()?->state() ?? new CertificateState('Sertifika yok', 'muted');
    }

    /**
     * Parameters for the agent's site.apply method.
     *
     * @return array<string, mixed>
     */
    public function agentSpec(): array
    {
        $spec = [
            'domain' => $this->domain,
            'user' => $this->systemUser->name,
            'php_version' => $this->php_version,
            'aliases' => array_values($this->aliases ?? []),
        ];
        if ($this->web_root !== null) {
            $spec['web_root'] = $this->web_root;
        }
        if ($this->hasHttps()) {
            $spec['tls'] = ['certificate' => $this->certificate->name, 'redirect' => $this->https_redirect];
        }
        if ($this->isSuspended()) {
            return $spec + ['suspended' => true];
        }

        return $spec + $this->laravelWeb();
    }

    /**
     * What the site's Laravel application asks of Nginx: Octane behind a
     * proxy, Reverb's WebSockets, and the maintenance page.
     *
     * @return array<string, mixed>
     */
    private function laravelWeb(): array
    {
        $app = $this->laravelApp;
        if ($app === null || $this->web_root === null || $this->web_root !== $app->webRoot()) {
            return [];
        }
        $web = [];
        foreach ($app->processes->where('enabled', true) as $process) {
            if ($process->kind === 'octane' && $process->port) {
                $web['proxy'] = ['port' => $process->port];
            }
            if ($process->kind === 'reverb' && $process->port) {
                // Clients connect to /app/<key>; the application's
                // broadcasts go to /apps/<id>/events.
                $web['websockets'] = [['path' => '/app/', 'port' => $process->port], ['path' => '/apps/', 'port' => $process->port]];
            }
        }
        if ($app->nginxMaintenance()) {
            $web['maintenance'] = [
                'allow' => $app->maintenanceAllowList(),
                'secret' => (string) $app->maintenance_secret,
                'retry' => $app->maintenance_retry,
                'html' => $app->maintenance_mode === 'custom' ? (string) $app->maintenance_html : '',
            ];
        }

        return $web;
    }
}
