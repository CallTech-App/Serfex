<?php

namespace App\Livewire\Mail;

use App\Enums\MailDomainStatus;
use App\Livewire\Attributes\ForCustomers;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailForwarder;
use App\Services\Agent\AgentClient;
use App\Services\Customers\Quotas;
use App\Services\Dns\Zones;
use App\Services\Mail\MailDns;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailPassword;
use App\Services\Mail\MailSettings;
use App\Services\Mail\MailUsage;
use App\Services\Stats\MailTraffic;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One mail domain: its mailboxes, forwarders and mail settings, with the
 * DNS records it needs.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'mail', except: ['setupServer'])]
class MailDomainShow extends Component
{
    use ChecksMailServer;

    /** Mailbox and forwarder names, as the agent accepts them. */
    private const LOCAL = '/^[a-z0-9](?:[a-z0-9._-]{0,62}[a-z0-9])?$/';

    public int $domainId;

    #[Url(except: 'mailboxes')]
    public string $tab = 'mailboxes';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    // Mailbox form
    public bool $editingMailbox = false;

    public ?int $mailboxId = null;

    public string $mbName = '';

    public string $mbPassword = '';

    public int $mbQuota = 1024;

    public string $mbForward = '';

    public bool $mbKeepCopy = true;

    public bool $mbAutoreply = false;

    public string $mbSubject = '';

    public string $mbBody = '';

    public string $mbUntil = '';

    // Forwarder form
    public bool $editingForwarder = false;

    public ?int $forwarderId = null;

    public string $fwName = '';

    public bool $fwCatchAll = false;

    public string $fwTo = '';

    // Settings tab
    public bool $spamFilter = true;

    public bool $manageDns = true;

    public bool $disabling = false;

    /**
     * Takes the id rather than a bound model, like SiteShow: polling a
     * domain whose mail was just turned off must not turn into a 404.
     */
    public function mount(int $domain, AgentClient $agent, MailSettings $settings, MailUsage $usage): void
    {
        $model = MailDomain::visibleTo(auth()->user())->findOrFail($domain);
        $this->domainId = $model->id;
        $this->fillSettings($model);
        $this->refreshServer($agent, $settings);
        if ($model->status === MailDomainStatus::Active) {
            $usage->refresh($model, onlyStale: true);
        }
    }

    /**
     * Polled while the agent works on the domain. Once its mail is gone for
     * good the page returns to the list.
     */
    public function refreshStatus(): void
    {
        if ($this->domain() === null) {
            session()->flash('toast', __('E-posta kapatıldı.'));
            $this->redirectRoute('mail.index', navigate: true);
        }
    }

    public function retry(MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain !== null && $domain->status === MailDomainStatus::Failed) {
            $domains->sync($domain);
        }
    }

    // Mailboxes

    public function openCreate(MailSettings $settings, Quotas $quotas): void
    {
        if ($refusal = $quotas->refusal($this->domain()?->site?->customer(), 'mailboxes')) {
            $this->dispatch('toast', message: $refusal);

            return;
        }
        $this->resetMailboxForm();
        $this->mbQuota = $settings->defaultQuotaMb();
        $this->mbPassword = MailPassword::generate();
        $this->editingMailbox = true;
    }

    public function openEdit(int $id): void
    {
        $mailbox = $this->domain()?->mailboxes()->find($id);
        if ($mailbox === null) {
            return;
        }
        $this->resetMailboxForm();
        $a = $mailbox->autoreply ?? [];
        $this->mailboxId = $mailbox->id;
        $this->mbName = $mailbox->name;
        $this->mbQuota = $mailbox->quota_mb;
        $this->mbForward = implode("\n", $mailbox->forward_to ?? []);
        $this->mbKeepCopy = $mailbox->keep_copy;
        $this->mbAutoreply = (bool) ($a['enabled'] ?? false);
        $this->mbSubject = (string) ($a['subject'] ?? '');
        $this->mbBody = (string) ($a['body'] ?? '');
        $this->mbUntil = (string) ($a['until'] ?? '');
        $this->editingMailbox = true;
    }

    public function generatePassword(): void
    {
        $this->mbPassword = MailPassword::generate();
    }

    public function saveMailbox(MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null || $domain->status === MailDomainStatus::Deleting) {
            return;
        }
        $mailbox = $this->mailboxId === null ? null : $domain->mailboxes()->find($this->mailboxId);
        if ($this->mailboxId !== null && $mailbox === null) {
            $this->editingMailbox = false;

            return;
        }
        $this->mbName = mb_strtolower(trim($this->mbName));
        $this->mbSubject = trim($this->mbSubject);
        $this->mbBody = trim($this->mbBody);
        $address = $domain->address($mailbox->name ?? $this->mbName);

        $this->validate([
            'mbQuota' => ['required', 'integer', 'min:0', 'max:10485760'],
            'mbSubject' => ['nullable', 'string', 'max:200'],
            'mbBody' => [$this->mbAutoreply ? 'required' : 'nullable', 'string', 'max:5000'],
            'mbUntil' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:today'],
        ], [
            'mbBody.required' => __('Otomatik yanıt için bir metin yazın.'),
            'mbUntil.after_or_equal' => __('Bitiş tarihi bugün ya da sonrası olmalı.'),
        ], [
            'mbQuota' => 'kota',
            'mbSubject' => 'konu',
            'mbBody' => __('yanıt metni'),
            'mbUntil' => __('bitiş tarihi'),
        ]);
        if ($mailbox === null && ($error = $this->nameError($domain, $this->mbName)) !== null) {
            $this->addError('mbName', $error);

            return;
        }
        if (($mailbox === null || $this->mbPassword !== '') && ($weak = MailPassword::weakness($this->mbPassword, $address)) !== null) {
            $this->addError('mbPassword', $weak);

            return;
        }
        $forward = $this->addresses($this->mbForward, 'mbForward', $address);
        if ($forward === null) {
            return;
        }

        if ($mailbox === null && ($refusal = app(Quotas::class)->refusal($domain->site?->customer(), 'mailboxes'))) {
            $this->addError('mbName', $refusal);

            return;
        }
        if ($mailbox === null) {
            $created = $domains->createMailbox($domain, $this->mbName, $this->mbPassword, $this->mbQuota);
            $this->editingMailbox = false;
            $this->dispatch('toast',
                message: __(':address oluşturuldu.', ['address' => $address]),
                source: $this->getId(),
                undo: ['method' => 'undoCreate', 'args' => [$created->id]],
            );

            return;
        }

        $token = $domains->updateMailbox($mailbox, [
            'quota_mb' => $this->mbQuota,
            'forward_to' => $forward,
            'keep_copy' => $forward === [] || $this->mbKeepCopy,
            'autoreply' => $this->mbAutoreply || $this->mbBody !== '' ? [
                'enabled' => $this->mbAutoreply,
                'subject' => $this->mbSubject,
                'body' => $this->mbBody,
                'until' => $this->mbUntil,
            ] : null,
        ], $this->mbPassword);
        $this->editingMailbox = false;
        $this->dispatch('toast',
            message: $this->mbPassword !== '' ? __(':address güncellendi. Yeni parola hemen geçerli.', ['address' => $address]) : __(':address güncellendi.', ['address' => $address]),
            source: $this->getId(),
            undo: ['method' => 'undoEdit', 'args' => [$token]],
        );
    }

    public function undoCreate(int $id, MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain !== null) {
            $domains->deleteMailboxes($domain, [$id]);
        }
    }

    public function undoEdit(string $token, MailDomains $domains): void
    {
        $this->dispatch('toast', message: $domains->undoMailbox($token) ? __('Değişiklik geri alındı.') : __('Değişiklik artık geri alınamıyor.'));
    }

    public function deleteSelected(MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null || $this->selected === []) {
            return;
        }
        $names = $domain->mailboxes()->whereKey($this->selected)->pluck('name');
        $ids = $domains->deleteMailboxes($domain, $this->selected);
        $this->selected = [];
        if ($ids === []) {
            return;
        }
        $this->dispatch('toast',
            message: (count($ids) === 1 ? __(':first silindi.', ['first' => $domain->address($names->first())]) : __(':ids posta kutusu silindi.', ['ids' => count($ids)]))
                .' '.__('Postalar :minutes dakika saklanır.', ['minutes' => intdiv(MailDomains::UNDO_SECONDS, 60)]),
            source: $this->getId(),
            undo: ['method' => 'undoDelete', 'args' => [$ids]],
        );
    }

    /**
     * @param  list<int>  $ids
     */
    public function undoDelete(array $ids, MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null) {
            return;
        }
        $restored = $domains->restoreMailboxes($domain, array_map('intval', $ids));
        $this->dispatch('toast', message: $restored === count($ids) ? __('Silme geri alındı.') : __('Bazı posta kutuları geri alınamadı; süre dolmuş olabilir.'));
    }

    // Forwarders

    public function openForwarder(?int $id = null): void
    {
        $this->reset('forwarderId', 'fwName', 'fwCatchAll', 'fwTo');
        $this->resetValidation();
        $forwarder = $id === null ? null : $this->domain()?->forwarders()->find($id);
        if ($forwarder !== null) {
            $this->forwarderId = $forwarder->id;
            $this->fwName = $forwarder->isCatchAll() ? '' : $forwarder->name;
            $this->fwCatchAll = $forwarder->isCatchAll();
            $this->fwTo = implode("\n", $forwarder->destinations);
        }
        $this->editingForwarder = true;
    }

    public function saveForwarder(MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null || $domain->status === MailDomainStatus::Deleting) {
            return;
        }
        $forwarder = $this->forwarderId === null ? null : $domain->forwarders()->find($this->forwarderId);
        $name = $forwarder?->name ?? ($this->fwCatchAll ? MailForwarder::CATCH_ALL : mb_strtolower(trim($this->fwName)));
        $this->fwName = $name === MailForwarder::CATCH_ALL ? '' : $name;
        if ($forwarder === null && $name !== MailForwarder::CATCH_ALL) {
            if (($error = $this->nameError($domain, $name)) !== null) {
                $this->addError('fwName', $error);

                return;
            }
        }
        if ($forwarder === null && $name === MailForwarder::CATCH_ALL && $domain->forwarders()->where('name', $name)->exists()) {
            $this->addError('fwName', __('Bu alan adının zaten bir tümünü yakala yönlendiricisi var; onu düzenleyin.'));

            return;
        }
        $to = $this->addresses($this->fwTo, 'fwTo', $domain->address($name));
        if ($to === null) {
            return;
        }
        if ($to === []) {
            $this->addError('fwTo', __('En az bir adres yazın.'));

            return;
        }

        $previous = $forwarder?->destinations;
        $domains->saveForwarder($domain, $name, $to);
        $this->editingForwarder = false;
        $label = $name === MailForwarder::CATCH_ALL ? __('Tümünü yakala yönlendiricisi') : $domain->address($name);
        $this->dispatch('toast',
            message: $forwarder === null ? __(':label eklendi.', ['label' => $label]) : __(':label güncellendi.', ['label' => $label]),
            source: $this->getId(),
            undo: ['method' => 'undoForwarder', 'args' => [$name, $previous]],
        );
    }

    public function deleteForwarder(int $id, MailDomains $domains): void
    {
        $forwarder = $this->domain()?->forwarders()->find($id);
        if ($forwarder === null) {
            return;
        }
        $saved = $domains->deleteForwarder($forwarder);
        $this->dispatch('toast',
            message: __(':name silindi.', ['name' => $forwarder->isCatchAll() ? __('Tümünü yakala yönlendiricisi') : $forwarder->domain->address($forwarder->name)]),
            source: $this->getId(),
            undo: ['method' => 'undoForwarder', 'args' => [$saved['name'], $saved['destinations']]],
        );
    }

    /**
     * Put a forwarder back as it was: removed when it did not exist.
     *
     * @param  list<string>|null  $destinations
     */
    public function undoForwarder(string $name, ?array $destinations, MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null) {
            return;
        }
        if ($destinations === null) {
            $forwarder = $domain->forwarders()->where('name', $name)->first();
            if ($forwarder !== null) {
                $domains->deleteForwarder($forwarder);
            }
        } elseif ($name === MailForwarder::CATCH_ALL || $this->nameError($domain, $name, forwarder: true) === null) {
            $domains->saveForwarder($domain, $name, $destinations);
        }
        $this->dispatch('toast', message: __('Değişiklik geri alındı.'));
    }

    // Settings

    public function saveSettings(MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null || $domain->status === MailDomainStatus::Deleting) {
            return;
        }
        $previous = ['spam_filter' => $domain->spam_filter, 'manage_dns' => $domain->manage_dns];
        $domain->update(['spam_filter' => $this->spamFilter, 'manage_dns' => $this->manageDns]);
        $domains->sync($domain);
        $this->dispatch('toast', message: __('Posta ayarları kaydedildi.'), source: $this->getId(), undo: ['method' => 'undoSettings', 'args' => [$previous]]);
    }

    /**
     * @param  array{spam_filter: bool, manage_dns: bool}  $previous
     */
    public function undoSettings(array $previous, MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null) {
            return;
        }
        $domain->update(['spam_filter' => (bool) $previous['spam_filter'], 'manage_dns' => (bool) $previous['manage_dns']]);
        $domains->sync($domain);
        $this->fillSettings($domain);
        $this->dispatch('toast', message: __('Değişiklik geri alındı.'));
    }

    /**
     * Write the recommended records, replacing ones that differ.
     */
    public function fixDns(MailDns $dns): void
    {
        $domain = $this->domain();
        if ($domain === null) {
            return;
        }
        $token = $dns->apply($domain, fix: true);
        if ($token === null) {
            $this->dispatch('toast', message: __('DNS kayıtları zaten önerilen değerlerde.'));

            return;
        }
        $this->dispatch('toast', message: __('Posta için DNS kayıtları yazıldı.'), source: $this->getId(), undo: ['method' => 'undoDns', 'args' => [$token]]);
    }

    public function undoDns(string $token, Zones $zones): void
    {
        $this->dispatch('toast', message: $zones->undo($token) !== null ? __('DNS değişikliği geri alındı.') : __('Değişiklik artık geri alınamıyor.'));
    }

    public function recheckDns(MailDns $dns): void
    {
        $domain = $this->domain();
        if ($domain !== null) {
            $dns->recheck($domain);
        }
    }

    public function disable(MailDomains $domains): void
    {
        $domain = $this->domain();
        if ($domain === null) {
            return;
        }
        $domains->disable($domain);
        $this->disabling = false;
        $this->dispatch('toast',
            message: __(':name için e-posta kapatıldı. Posta kutuları :undo_seconds dakika sonra silinir.', ['name' => $domain->name, 'undo_seconds' => intdiv(MailDomains::UNDO_SECONDS, 60)]),
            source: $this->getId(),
            undo: ['method' => 'undoDisable', 'args' => []],
        );
    }

    public function undoDisable(MailDomains $domains): void
    {
        $domain = $this->domain();
        $this->dispatch('toast', message: $domain !== null && $domains->restore($domain) ? __('E-posta yeniden açıldı.') : __('Artık geri alınamıyor.'));
    }

    public function render(MailDns $dns, MailSettings $settings, MailTraffic $traffic): View
    {
        $domain = $this->domain();
        if ($domain === null) {
            // Purged meanwhile; refreshStatus() sends the browser back to the list.
            return view('livewire.sites.gone');
        }

        $mailboxes = $domain->mailboxes()
            ->when($this->search !== '', fn ($q) => $q->where('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], mb_strtolower($this->search)).'%'))
            ->get()
            ->each->setRelation('domain', $domain);

        return view('livewire.mail.show', [
            'domain' => $domain,
            'mailboxes' => $mailboxes,
            'mailboxCount' => $domain->mailboxes()->count(),
            'forwarders' => $domain->forwarders()->get(),
            'records' => $this->tab === 'settings' ? $dns->check($domain) : [],
            'zone' => $this->tab === 'settings' ? $dns->zoneFor($domain) : null,
            'hostname' => $settings->hostname(),
            'webmail' => $settings->webmailUrl(),
            'quotas' => MailSettings::QUOTAS,
            'traffic' => $this->tab === 'traffic' ? $traffic->days([$domain->name]) : null,
        ])->title(__(':name · E-posta · Serfex', ['name' => $domain->name]));
    }

    private function domain(): ?MailDomain
    {
        return MailDomain::visibleTo(auth()->user())->with('site.systemUser.customer.package')->find($this->domainId);
    }

    private function fillSettings(MailDomain $domain): void
    {
        $this->spamFilter = $domain->spam_filter;
        $this->manageDns = $domain->manage_dns;
    }

    private function resetMailboxForm(): void
    {
        $this->reset('mailboxId', 'mbName', 'mbPassword', 'mbForward', 'mbKeepCopy', 'mbAutoreply', 'mbSubject', 'mbBody', 'mbUntil');
        $this->resetValidation();
    }

    /**
     * Why $name cannot be a new mailbox or forwarder, or null.
     */
    public static function nameError(MailDomain $domain, string $name, bool $forwarder = false): ?string
    {
        if ($name === '') {
            return __('Adresin @ işaretinden önceki kısmını yazın.');
        }
        if (! preg_match(self::LOCAL, $name) || str_contains($name, '..')) {
            return __('Yalnızca küçük harf, rakam, nokta, tire ve alt çizgi kullanın; harf ya da rakamla başlayıp bitmeli.');
        }
        $mailbox = Mailbox::withTrashed()->where('mail_domain_id', $domain->id)->where('name', $name)->first();
        if ($mailbox?->trashed()) {
            return __('Bu adres az önce silindi; postaları silinene kadar (:undo_seconds dakika) yeniden kullanılamaz. Silmeyi geri alabilirsiniz.', ['undo_seconds' => intdiv(MailDomains::UNDO_SECONDS, 60)]);
        }
        if ($mailbox !== null) {
            return __('Bu adreste zaten bir posta kutusu var.');
        }
        if (! $forwarder && $domain->forwarders()->where('name', $name)->exists()) {
            return __('Bu adres bir yönlendirici olarak kullanılıyor.');
        }

        return null;
    }

    /**
     * Parse a list of addresses (one per line or comma separated). Adds an
     * error on $field and returns null when one is invalid.
     *
     * @return list<string>|null
     */
    private function addresses(string $text, string $field, string $self): ?array
    {
        [$list, $error] = self::parseAddresses($text, $self);
        if ($error !== null) {
            $this->addError($field, $error);
        }

        return $list;
    }

    /**
     * The same for the panel API: the list, or null and why.
     *
     * @return array{0: list<string>|null, 1: string|null}
     */
    public static function parseAddresses(string $text, string $self): array
    {
        $list = array_values(array_unique(array_filter(array_map(
            fn ($a) => mb_strtolower(trim($a)),
            preg_split('/[\s,;]+/', $text) ?: [],
        ))));
        if (count($list) > 50) {
            return [null, __('En fazla 50 adres yazabilirsiniz.')];
        }
        foreach ($list as $a) {
            if (filter_var($a, FILTER_VALIDATE_EMAIL) === false) {
                return [null, __('":a" geçerli bir e-posta adresi değil.', ['a' => $a])];
            }
            if ($a === $self) {
                return [null, __('Bir adres kendisine yönlendirilemez.')];
            }
        }

        return [$list, null];
    }
}
