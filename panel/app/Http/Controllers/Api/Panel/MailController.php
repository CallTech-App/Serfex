<?php

namespace App\Http\Controllers\Api\Panel;

use App\Enums\MailDomainStatus;
use App\Livewire\Mail\MailDomainShow;
use App\Models\Mailbox;
use App\Models\MailDomain;
use App\Models\MailForwarder;
use App\Models\Site;
use App\Services\Customers\Quotas;
use App\Services\Mail\MailDomains;
use App\Services\Mail\MailPassword;
use App\Services\Mail\MailSettings;
use App\Support\Hostnames;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * E-posta: the mail domains, their mailboxes and forwarders. A password
 * the caller does not give is made here and returned once; the panel
 * keeps only its hash.
 */
class MailController extends ApiController
{
    public function __construct(private readonly MailDomains $domains) {}

    public function index(Request $request): JsonResponse
    {
        $domains = MailDomain::visibleTo($this->user($request))->withCount('mailboxes', 'forwarders')->orderBy('name')->get();

        return response()->json(['data' => $domains->map(fn (MailDomain $d) => [
            'name' => $d->name,
            'status' => $d->status->value,
            'mailboxes' => $d->mailboxes_count,
            'forwarders' => $d->forwarders_count,
            'last_error' => $d->last_error,
        ])->values()]);
    }

    public function show(Request $request, string $domain): JsonResponse
    {
        return response()->json(['data' => $this->domainData($this->find($request, $domain))]);
    }

    /**
     * POST /api/v1/mail/domains {name}: turns mail on for a domain (a
     * customer: one of their sites' domains).
     */
    public function enable(Request $request, MailSettings $settings): JsonResponse
    {
        $user = $this->user($request);
        $name = Hostnames::normalize((string) $request->validate(['name' => ['required', 'string', 'max:253']], [], ['name' => __('alan adı')])['name']);
        if (! Hostnames::isValidDomain($name)) {
            return $this->refuse(__('Geçerli bir alan adı girin, örneğin ornek.com.tr.'));
        }
        if (! $settings->isSetUp()) {
            return $this->refuse(__('Posta sunucusu henüz kurulmadı; önce panelde E-posta sayfasından kurun.'), 409);
        }
        if ($name === $settings->hostname()) {
            return $this->refuse(__('Bu ad posta sunucusunun kendi adı; e-postayı alan adının kendisi için açın.'));
        }
        $site = Site::visibleTo($user)->where('domain', $name)->first();
        if (! $user->is_admin && $site === null && ! Site::visibleTo($user)->whereJsonContains('aliases', $name)->exists()) {
            return $this->refuse(__('E-postayı yalnızca kendi sitelerinizin alan adları için açabilirsiniz.'), 403);
        }
        $existing = MailDomain::where('name', $name)->first();
        if ($existing !== null && $existing->status !== MailDomainStatus::Deleting) {
            return $this->refuse(__('Bu alan adında e-posta zaten açık.'), 409);
        }
        $domain = $this->domains->enable($name, $site);
        $this->audit($request, __('E-posta'), $existing ? __(':name için e-posta yeniden açıldı; posta kutuları geri geldi.', ['name' => $name]) : __(':name için e-posta açıldı.', ['name' => $name]));

        return response()->json(['data' => $this->domainData($domain->fresh())], 202);
    }

    /**
     * POST /api/v1/mail/domains/{domain}/mailboxes {name, password?, quota_mb?}
     */
    public function storeMailbox(Request $request, MailSettings $settings, Quotas $quotas, string $domain): JsonResponse
    {
        $domain = $this->find($request, $domain, writable: true);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:64'],
            'password' => ['nullable', 'string', 'max:200'],
            'quota_mb' => ['nullable', 'integer', 'min:0', 'max:10485760'],
        ], [], ['name' => __('ad'), 'password' => __('parola'), 'quota_mb' => __('kota')]);
        $name = mb_strtolower(trim($data['name']));
        if (($error = MailDomainShow::nameError($domain, $name)) !== null) {
            return $this->refuse($error);
        }
        $address = $domain->address($name);
        $generated = ($data['password'] ?? '') === '';
        $password = $generated ? MailPassword::generate() : $data['password'];
        if (($weak = MailPassword::weakness($password, $address)) !== null) {
            return $this->refuse($weak);
        }
        if ($refusal = $quotas->refusal($domain->site?->customer(), 'mailboxes')) {
            return $this->refuse($refusal);
        }
        $mailbox = $this->domains->createMailbox($domain, $name, $password, (int) ($data['quota_mb'] ?? $settings->defaultQuotaMb()));
        Quotas::forget();
        $this->audit($request, __('E-posta · :name', ['name' => $domain->name]), __(':address oluşturuldu.', ['address' => $address]));

        return response()->json(['data' => $this->mailbox($domain, $mailbox) + ['password' => $generated ? $password : null]], 201);
    }

    /**
     * PATCH /api/v1/mail/domains/{domain}/mailboxes/{name} {password?, generate?, quota_mb?}
     */
    public function updateMailbox(Request $request, string $domain, string $name): JsonResponse
    {
        $domain = $this->find($request, $domain, writable: true);
        $mailbox = $domain->mailboxes()->where('name', $name)->first() ?? $this->missing(__(':address adında bir posta kutusu yok.', ['address' => $domain->address($name)]));
        $data = $request->validate([
            'password' => ['nullable', 'string', 'max:200'],
            'generate' => ['nullable', 'boolean'],
            'quota_mb' => ['nullable', 'integer', 'min:0', 'max:10485760'],
        ], [], ['password' => __('parola'), 'quota_mb' => __('kota')]);
        $generated = (bool) ($data['generate'] ?? false) && ($data['password'] ?? '') === '';
        $password = $generated ? MailPassword::generate() : ($data['password'] ?? '');
        if ($password !== '' && ($weak = MailPassword::weakness($password, $domain->address($name))) !== null) {
            return $this->refuse($weak);
        }
        $values = isset($data['quota_mb']) ? ['quota_mb' => (int) $data['quota_mb']] : [];
        if ($values === [] && $password === '') {
            return $this->refuse(__('Değiştirilecek bir şey yok: yeni parola ya da kota verin.'));
        }
        $this->domains->updateMailbox($mailbox, $values, $password);
        $this->audit($request, __('E-posta · :name', ['name' => $domain->name]), $password !== '' ? __(':address güncellendi. Yeni parola hemen geçerli.', ['address' => $domain->address($name)]) : __(':address güncellendi.', ['address' => $domain->address($name)]));

        return response()->json(['data' => $this->mailbox($domain, $mailbox->fresh()) + ['password' => $generated ? $password : null]]);
    }

    /**
     * DELETE /api/v1/mail/domains/{domain}/mailboxes/{name}: the mailbox
     * stops at once; its mail is deleted after ten minutes.
     */
    public function destroyMailbox(Request $request, string $domain, string $name): JsonResponse
    {
        $domain = $this->find($request, $domain, writable: true);
        $mailbox = $domain->mailboxes()->where('name', $name)->first() ?? $this->missing(__(':address adında bir posta kutusu yok.', ['address' => $domain->address($name)]));
        $this->domains->deleteMailboxes($domain, [$mailbox->id]);
        Quotas::forget();
        $this->audit($request, __('E-posta · :name', ['name' => $domain->name]), __(':address silindi.', ['address' => $mailbox->address()]));

        return response()->json(null, 204);
    }

    /**
     * PUT /api/v1/mail/domains/{domain}/forwarders/{name} {to}: name "*"
     * is the catch-all; to is a list or a text of addresses.
     */
    public function saveForwarder(Request $request, string $domain, string $name): JsonResponse
    {
        $domain = $this->find($request, $domain, writable: true);
        $name = mb_strtolower($name);
        $request->validate(['to' => ['required']], [], ['to' => __('hedef')]);
        $existing = $domain->forwarders()->where('name', $name)->first();
        if ($existing === null && $name !== MailForwarder::CATCH_ALL && ($error = MailDomainShow::nameError($domain, $name)) !== null) {
            return $this->refuse($error);
        }
        $to = $request->input('to');
        [$list, $error] = MailDomainShow::parseAddresses(is_array($to) ? implode(',', $to) : (string) $to, $domain->address($name));
        if ($error !== null) {
            return $this->refuse($error);
        }
        if ($list === []) {
            return $this->refuse(__('En az bir adres yazın.'));
        }
        $forwarder = $this->domains->saveForwarder($domain, $name, $list);
        $label = $name === MailForwarder::CATCH_ALL ? __('Tümünü yakala yönlendiricisi') : $domain->address($name);
        $this->audit($request, __('E-posta · :name', ['name' => $domain->name]), $existing === null ? __(':label eklendi.', ['label' => $label]) : __(':label güncellendi.', ['label' => $label]));

        return response()->json(['data' => ['name' => $forwarder->name, 'address' => $name === MailForwarder::CATCH_ALL ? '*@'.$domain->name : $domain->address($name), 'to' => $forwarder->destinations]], $existing ? 200 : 201);
    }

    public function destroyForwarder(Request $request, string $domain, string $name): JsonResponse
    {
        $domain = $this->find($request, $domain, writable: true);
        $forwarder = $domain->forwarders()->where('name', mb_strtolower($name))->first() ?? $this->missing(__(':address adında bir yönlendirici yok.', ['address' => $domain->address($name)]));
        $this->domains->deleteForwarder($forwarder);
        $this->audit($request, __('E-posta · :name', ['name' => $domain->name]), __(':label silindi.', ['label' => $forwarder->name === MailForwarder::CATCH_ALL ? __('Tümünü yakala yönlendiricisi') : $domain->address($forwarder->name)]));

        return response()->json(null, 204);
    }

    private function find(Request $request, string $name, bool $writable = false): MailDomain
    {
        $domain = MailDomain::visibleTo($this->user($request))->with('site.systemUser.customer.package')->where('name', mb_strtolower($name))->first()
            ?? $this->missing(__(':name için e-posta açık değil.', ['name' => $name]));
        if ($writable && $domain->status === MailDomainStatus::Deleting) {
            $this->missing(__(':name için e-posta kapatılıyor.', ['name' => $domain->name]));
        }

        return $domain;
    }

    /**
     * @return array<string, mixed>
     */
    private function domainData(MailDomain $domain): array
    {
        return [
            'name' => $domain->name,
            'status' => $domain->status->value,
            'site' => $domain->site?->domain,
            'spam_filter' => (bool) $domain->spam_filter,
            'last_error' => $domain->last_error,
            'mailboxes' => $domain->mailboxes()->orderBy('name')->get()->map(fn (Mailbox $m) => $this->mailbox($domain, $m))->values(),
            'forwarders' => $domain->forwarders()->orderBy('name')->get()->map(fn (MailForwarder $f) => [
                'name' => $f->name,
                'address' => $f->name === MailForwarder::CATCH_ALL ? '*@'.$domain->name : $domain->address($f->name),
                'to' => $f->destinations,
            ])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mailbox(MailDomain $domain, Mailbox $m): array
    {
        return [
            'address' => $domain->address($m->name),
            'name' => $m->name,
            'quota_mb' => $m->quota_mb,
            'usage_bytes' => $m->usage_bytes,
            'forward_to' => $m->forward_to ?? [],
        ];
    }
}
