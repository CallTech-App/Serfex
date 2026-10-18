<?php

namespace App\Services\Mail;

use App\Enums\MailDomainStatus;
use App\Jobs\DeleteMailDomain;
use App\Jobs\PurgeMailbox;
use App\Jobs\SyncMailDomain;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailForwarder;
use App\Models\Site;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Turns mail on and off for domains and changes their mailboxes and
 * forwarders, then queues the agent work that makes the mail server follow.
 */
class MailDomains
{
    /** How long an Undo stays possible, in seconds; removed mail is kept that long. */
    public const UNDO_SECONDS = 600;

    public function __construct(
        private readonly MailSettings $settings,
        private readonly MailDns $dns,
    ) {}

    /**
     * Turn mail on for a domain. A domain whose mail was just turned off
     * comes back with its mailboxes.
     */
    public function enable(string $name, ?Site $site = null): MailDomain
    {
        $domain = MailDomain::where('name', $name)->first();
        if ($domain !== null) {
            if ($domain->status === MailDomainStatus::Deleting) {
                $this->restore($domain);
            }

            return $domain;
        }
        $site ??= Site::where('domain', $name)->first();
        $domain = MailDomain::create(['name' => $name, 'site_id' => $site?->id, 'status' => MailDomainStatus::Syncing]);
        SyncMailDomain::dispatch($domain);

        return $domain;
    }

    /**
     * Like cPanel and Plesk, a new site gets mail once the mail server is
     * set up, unless the administrator turned that off.
     */
    public function forSite(Site $site): ?MailDomain
    {
        if (! $this->settings->autoEnable() || ! $this->settings->isSetUp() || MailDomain::where('name', $site->domain)->exists()) {
            return null;
        }

        return $this->enable($site->domain, $site);
    }

    /**
     * Stop serving the domain's mail. Its mailboxes and stored mail stay for
     * UNDO_SECONDS (unless $purgeNow), then go for good.
     */
    public function disable(MailDomain $domain, bool $purgeNow = false): void
    {
        if ($domain->status === MailDomainStatus::Deleting && ! $purgeNow) {
            return;
        }
        $domain->update(['status' => MailDomainStatus::Deleting, 'disabled_at' => now(), 'last_error' => null]);
        if ($domain->manage_dns) {
            $this->dns->remove($domain);
        }
        DeleteMailDomain::dispatch($domain, purge: $purgeNow);
    }

    /**
     * Undo disable(): serve the domain again with everything it had.
     */
    public function restore(MailDomain $domain): bool
    {
        if ($domain->status !== MailDomainStatus::Deleting) {
            return false;
        }
        $domain->update(['status' => MailDomainStatus::Syncing, 'disabled_at' => null, 'last_error' => null]);
        SyncMailDomain::dispatch($domain);

        return true;
    }

    /**
     * Send the domain to the agent again (after any change).
     */
    public function sync(MailDomain $domain): void
    {
        if ($domain->status === MailDomainStatus::Deleting) {
            return;
        }
        $domain->update(['status' => MailDomainStatus::Syncing, 'last_error' => null]);
        SyncMailDomain::dispatch($domain);
    }

    /**
     * Add a mailbox; the password is hashed here and never stored.
     */
    public function createMailbox(MailDomain $domain, string $name, string $password, int $quotaMb): Mailbox
    {
        $mailbox = $domain->mailboxes()->create([
            'name' => $name,
            'password_hash' => MailPassword::hash($password),
            'quota_mb' => $quotaMb,
        ]);
        $this->sync($domain);

        return $mailbox;
    }

    /**
     * Change a mailbox; a new password is hashed here. Returns an undo token.
     *
     * @param  array<string, mixed>  $values
     */
    public function updateMailbox(Mailbox $mailbox, array $values, ?string $password = null): string
    {
        $token = $this->rememberMailbox($mailbox);
        if ($password !== null && $password !== '') {
            $values['password_hash'] = MailPassword::hash($password);
        }
        $mailbox->update($values);
        $this->sync($mailbox->domain);

        return $token;
    }

    /**
     * Undo updateMailbox().
     */
    public function undoMailbox(string $token): bool
    {
        $saved = Cache::pull('mail-undo:'.$token);
        $mailbox = $saved === null ? null : Mailbox::find($saved['id']);
        if ($mailbox === null) {
            return false;
        }
        $mailbox->update($saved['values']);
        $this->sync($mailbox->domain);

        return true;
    }

    /**
     * Remove mailboxes. They stop at once; their mail is deleted after
     * UNDO_SECONDS unless restoreMailboxes() brings them back.
     *
     * @param  list<int>  $ids
     * @return list<int> the ids removed
     */
    public function deleteMailboxes(MailDomain $domain, array $ids): array
    {
        $mailboxes = $domain->mailboxes()->whereKey($ids)->get();
        foreach ($mailboxes as $mailbox) {
            $mailbox->delete();
            PurgeMailbox::dispatch($mailbox)->delay(now()->addSeconds(self::UNDO_SECONDS));
        }
        if ($mailboxes->isNotEmpty()) {
            $this->sync($domain);
        }

        return $mailboxes->modelKeys();
    }

    /**
     * Undo deleteMailboxes() for the ones whose mail is still there.
     *
     * @param  list<int>  $ids
     */
    public function restoreMailboxes(MailDomain $domain, array $ids): int
    {
        $trashed = Mailbox::onlyTrashed()->where('mail_domain_id', $domain->id)->whereKey($ids)->get();
        foreach ($trashed as $mailbox) {
            $mailbox->restore();
        }
        if ($trashed->isNotEmpty()) {
            $this->sync($domain);
        }

        return $trashed->count();
    }

    /**
     * Add or replace a forwarder ("*" is the catch-all).
     *
     * @param  list<string>  $destinations
     */
    public function saveForwarder(MailDomain $domain, string $name, array $destinations): MailForwarder
    {
        $forwarder = $domain->forwarders()->updateOrCreate(['name' => $name], ['destinations' => array_values($destinations)]);
        $this->sync($domain);

        return $forwarder;
    }

    /**
     * Remove a forwarder; returns what Undo needs to put it back.
     *
     * @return array{name: string, destinations: list<string>}
     */
    public function deleteForwarder(MailForwarder $forwarder): array
    {
        $saved = ['name' => $forwarder->name, 'destinations' => array_values($forwarder->destinations)];
        $forwarder->delete();
        $this->sync($forwarder->domain);

        return $saved;
    }

    private function rememberMailbox(Mailbox $mailbox): string
    {
        $token = Str::random(24);
        Cache::put('mail-undo:'.$token, [
            'id' => $mailbox->id,
            'values' => $mailbox->only(['password_hash', 'quota_mb', 'forward_to', 'keep_copy', 'autoreply']),
        ], self::UNDO_SECONDS);

        return $token;
    }
}
