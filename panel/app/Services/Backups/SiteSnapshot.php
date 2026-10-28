<?php

namespace App\Services\Backups;

use App\Enums\LaravelAppStatus;
use App\Enums\MailDomainStatus;
use App\Models\Certificate;
use App\Models\Database;
use App\Models\DatabaseUser;
use App\Models\DnsZone;
use App\Models\MailDomain;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * What a site backup holds, from the panel's side: the archive the agent
 * is asked for, and the panel's description of the site (site.json) that
 * a restore uses to create it again on this or another server.
 */
class SiteSnapshot
{
    /**
     * The archive to ask the agent for. $home adds the account's files
     * outside its sites (once per account in a server backup).
     *
     * @return array<string, mixed>
     */
    public function item(Site $site, bool $home): array
    {
        $site->loadMissing('systemUser', 'laravelApp', 'subdomains', 'redirects');
        $databases = $this->databases($site);
        $mail = $this->mailDomain($site);
        $app = $site->laravelApp;

        return [
            'name' => $site->domain,
            'site' => ['domain' => $site->domain, 'user' => $site->systemUser->name],
            'home' => $home,
            'databases' => $databases->map(fn (Database $d) => ['server' => $d->server, 'name' => $d->name])->values()->all(),
            'database_users' => $this->users($databases)->map(fn (DatabaseUser $u) => ['server' => $u->server, 'name' => $u->name])->values()->all(),
            'mail' => $mail !== null,
            'certificates' => $this->certificates($site)->pluck('name')->values()->all(),
            // An application whose database Serfex does not manage (SQLite,
            // or one elsewhere) is dumped the way its own backups do it.
            'laravel' => $app !== null && $app->status === LaravelAppStatus::Active && $app->database_id === null && ! $app->isPreview()
                ? [$app->agentApp()] : [],
            'meta' => $this->meta($site),
        ];
    }

    /**
     * The panel's description of the site.
     *
     * @return array<string, mixed>
     */
    public function meta(Site $site): array
    {
        $site->loadMissing('systemUser', 'laravelApp', 'subdomains', 'redirects');
        $databases = $this->databases($site);
        $mail = $this->mailDomain($site)?->load('mailboxes', 'forwarders');
        $zone = DnsZone::with('records')->where('site_id', $site->id)->orderByRaw('name = ? desc', [$site->domain])->first();

        return [
            'version' => 1,
            'site' => [
                'domain' => $site->domain,
                'aliases' => array_values($site->aliases ?? []),
                'php_version' => $site->php_version,
                'web_server' => $site->web_server,
                'web_root' => $site->web_root,
                'https_redirect' => (bool) $site->https_redirect,
                'subdomains' => $site->subdomains->map(fn ($d) => $d->only(['name', 'web_root']))->values()->all(),
                'redirects' => $site->redirects->map(fn ($r) => $r->only(['kind', 'source', 'with_www', 'target', 'code', 'keep_path']))->values()->all(),
                'error_pages' => $site->error_pages,
                'nginx_directives' => $site->nginx_directives,
                'apache_directives' => $site->apache_directives,
            ],
            'account' => ['name' => $site->systemUser->name, 'customer_id' => $site->systemUser->customer_id],
            'databases' => $databases->map(fn (Database $d) => ['server' => $d->server, 'name' => $d->name])->values()->all(),
            'database_users' => $this->users($databases)->map(fn (DatabaseUser $u) => [
                'server' => $u->server,
                'name' => $u->name,
                'hosts' => array_values($u->hosts ?? []),
                'grants' => $u->databases->whereIn('id', $databases->pluck('id'))->map(fn (Database $d) => ['database' => $d->name, 'access' => $d->pivot->access])->values()->all(),
            ])->values()->all(),
            'mail' => $mail === null ? null : [
                'spam_filter' => (bool) $mail->spam_filter,
                'manage_dns' => (bool) $mail->manage_dns,
                'mailboxes' => $mail->mailboxes->map(fn ($m) => [
                    'name' => $m->name,
                    'password_hash' => $m->password_hash,
                    'quota_mb' => $m->quota_mb,
                    'forward_to' => $m->forward_to,
                    'keep_copy' => (bool) $m->keep_copy,
                    'autoreply' => $m->autoreply,
                ])->values()->all(),
                'forwarders' => $mail->forwarders->map(fn ($f) => ['name' => $f->name, 'destinations' => $f->destinations])->values()->all(),
            ],
            'dns' => $zone === null ? null : [
                // With its DNSSEC key, so that a restored zone keeps the DS
                // record at the registrar valid, on this server or another.
                'zone' => $zone->only(['name', 'hostmaster', 'default_ttl', 'refresh', 'retry', 'expire', 'minimum', 'dnssec', 'dnssec_key']),
                'records' => $zone->records->map(fn ($r) => $r->only(['name', 'type', 'content', 'priority', 'ttl']))->values()->all(),
            ],
            'certificates' => $this->certificates($site)->map(fn (Certificate $c) => [
                ...$c->only(['name', 'kind', 'source', 'domains', 'challenge', 'issuer', 'serial', 'fingerprint', 'auto_renew']),
                'not_before' => $c->not_before?->toIso8601String(),
                'not_after' => $c->not_after?->toIso8601String(),
                'in_use' => $c->id === $site->certificate_id,
            ])->values()->all(),
            'laravel' => $site->laravelApp === null ? null : ['path' => $site->laravelApp->path],
        ];
    }

    /**
     * @return Collection<int, Database>
     */
    private function databases(Site $site): Collection
    {
        return Database::where('site_id', $site->id)->orderBy('name')->get();
    }

    /**
     * Users with access to the databases.
     *
     * @param  Collection<int, Database>  $databases
     * @return Collection<int, DatabaseUser>
     */
    private function users(Collection $databases): Collection
    {
        if ($databases->isEmpty()) {
            return collect();
        }

        return DatabaseUser::with('databases')
            ->whereHas('databases', fn ($q) => $q->whereIn('databases.id', $databases->pluck('id')))
            ->orderBy('name')->get();
    }

    private function mailDomain(Site $site): ?MailDomain
    {
        return MailDomain::where('name', $site->domain)->where('status', '!=', MailDomainStatus::Deleting)->first();
    }

    /**
     * Installed certificates of the site.
     *
     * @return Collection<int, Certificate>
     */
    private function certificates(Site $site): Collection
    {
        return Certificate::where('site_id', $site->id)->whereNotNull('not_after')->orderBy('id')->get();
    }
}
