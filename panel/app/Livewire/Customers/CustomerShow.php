<?php

namespace App\Livewire\Customers;

use App\Models\Customer;
use App\Models\Package;
use App\Models\Site;
use App\Models\SystemUser;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Customers\Usage;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * One customer: usage against their package, their sites, and their
 * contact details, package and login.
 */
#[Layout('components.layouts.app')]
class CustomerShow extends Component
{
    public int $customerId;

    /** kullanim, siteler or bilgiler. */
    #[Url(as: 'sekme', except: 'kullanim')]
    public string $tab = 'kullanim';

    public string $company = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $notes = '';

    public ?int $packageId = null;

    public bool $suspending = false;

    public string $reason = '';

    public bool $deleting = false;

    public string $confirmName = '';

    public bool $deleteSites = false;

    public bool $attaching = false;

    public ?int $accountId = null;

    public ?string $inviteLink = null;

    public ?string $agentError = null;

    /**
     * Takes the id rather than a bound model, like SiteShow.
     */
    public function mount(int $customer): void
    {
        $this->customerId = Customer::findOrFail($customer)->id;
        $this->fillInfo();
    }

    public function measure(Usage $usage): void
    {
        $this->agentError = null;
        try {
            $usage->measureDisk($this->customer()->systemUsers()->get());
        } catch (AgentException $e) {
            $this->agentError = 'Disk ölçülemedi: '.($e->reason ?: $e->getMessage());

            return;
        }
        $this->dispatch('toast', message: 'Disk kullanımı ölçüldü.');
    }

    public function saveInfo(Customers $customers): void
    {
        $customer = $this->customer();
        $this->email = mb_strtolower(trim($this->email));
        $this->validate([
            'company' => ['nullable', 'max:120'],
            'name' => ['required', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')->ignore($customer->user?->id)],
            'phone' => ['nullable', 'max:40', 'regex:/^[0-9+() .-]+$/'],
            'notes' => ['nullable', 'max:5000'],
            'packageId' => ['required', Rule::exists('packages', 'id')],
        ], [
            'email.unique' => 'Bu e-posta adresiyle bir panel hesabı zaten var.',
            'phone.regex' => 'Telefon numarası geçersiz.',
        ], ['company' => 'firma adı', 'name' => 'yetkili kişi', 'email' => 'e-posta', 'phone' => 'telefon', 'notes' => 'notlar', 'packageId' => 'paket']);

        $customer->update([
            'company' => trim($this->company) ?: null,
            'name' => trim($this->name),
            'email' => $this->email,
            'phone' => trim($this->phone) ?: null,
            'notes' => trim($this->notes) ?: null,
            'package_id' => $this->packageId,
        ]);
        // The login carries the same name and address.
        $customer->user?->update(['name' => $customer->name, 'email' => $customer->email]);
        Quotas::forget();
        $this->dispatch('toast', message: 'Müşteri bilgileri kaydedildi.');
    }

    public function newInvite(Customers $customers): void
    {
        $this->inviteLink = route('invitation', $customers->newInvite($this->customer()));
    }

    public function closeInvite(): void
    {
        $this->inviteLink = null;
    }

    public function openSuspend(): void
    {
        $this->reason = '';
        $this->suspending = true;
    }

    public function suspend(Customers $customers): void
    {
        $this->validate(['reason' => ['nullable', 'max:200']]);
        $customers->suspend($this->customer(), trim($this->reason) ?: null);
        $this->suspending = false;
        $this->dispatch('toast', message: 'Müşteri askıya alındı. Siteleri askı sayfası gösteriyor.', source: $this->getId(),
            undo: ['method' => 'unsuspend', 'args' => []]);
    }

    public function unsuspend(Customers $customers): void
    {
        $customers->unsuspend($this->customer());
        $this->dispatch('toast', message: 'Müşteri yeniden etkin.');
    }

    public function openDelete(): void
    {
        $this->reset('confirmName', 'deleteSites');
        $this->resetValidation();
        $this->deleting = true;
    }

    public function delete(Customers $customers): void
    {
        $customer = $this->customer();
        if ($this->confirmName !== $customer->username) {
            $this->addError('confirmName', 'Sistem kullanıcısını tam olarak yazın.');

            return;
        }
        $customers->delete($customer, $this->deleteSites);
        Quotas::forget();
        session()->flash('toast', $customer->displayName().' silinecek. '.(Customers::UNDO_SECONDS / 60).' dakika içinde listeden geri alabilirsiniz.');
        $this->redirectRoute('customers.index', navigate: true);
    }

    public function openAttach(): void
    {
        $this->accountId = $this->freeAccounts()->first()?->id;
        $this->resetValidation();
        $this->attaching = true;
    }

    public function attach(Customers $customers): void
    {
        $account = $this->freeAccounts()->firstWhere('id', $this->accountId);
        if ($account === null) {
            $this->addError('accountId', 'Bağlanacak bir hesap seçin.');

            return;
        }
        $customers->attach($this->customer(), $account);
        Quotas::forget();
        $this->attaching = false;
        $this->dispatch('toast', message: "{$account->name} hesabı ve siteleri müşteriye bağlandı.", source: $this->getId(),
            undo: ['method' => 'undoAttach', 'args' => [$account->id]]);
    }

    public function undoAttach(int $accountId, Customers $customers): void
    {
        $account = SystemUser::where('customer_id', $this->customerId)->find($accountId);
        if ($account !== null) {
            $customers->detach($this->customer(), $account);
            Quotas::forget();
        }
    }

    public function detach(int $accountId, Customers $customers): void
    {
        $account = SystemUser::where('customer_id', $this->customerId)->find($accountId);
        if ($account === null) {
            return;
        }
        $customers->detach($this->customer(), $account);
        Quotas::forget();
        $this->dispatch('toast', message: "{$account->name} hesabı müşteriden ayrıldı; siteleri sunucuda kalıyor.", source: $this->getId(),
            undo: ['method' => 'undoDetach', 'args' => [$account->id]]);
    }

    public function undoDetach(int $accountId, Customers $customers): void
    {
        $account = SystemUser::whereNull('customer_id')->find($accountId);
        if ($account !== null) {
            $customers->attach($this->customer(), $account);
            Quotas::forget();
        }
    }

    public function render(Quotas $quotas): View
    {
        $customer = $this->customer();
        if ($customer === null) {
            return view('livewire.customers.gone');
        }

        return view('livewire.customers.show', [
            'customer' => $customer,
            'report' => $quotas->report($customer),
            'measuredAt' => $customer->systemUsers()->max('disk_measured_at'),
            'accounts' => $customer->systemUsers()->with(['sites' => fn ($q) => $q->whereDoesntHave('preview')->with('certificate', 'laravelApp')->orderBy('domain')])->orderBy('name')->get(),
            'freeAccounts' => $this->attaching ? $this->freeAccounts() : collect(),
            'packages' => Package::orderBy('id')->get(),
        ])->title($customer->displayName().' · Servik');
    }

    /**
     * Accounts that belong to no customer and hold at least one site.
     *
     * @return Collection<int, SystemUser>
     */
    private function freeAccounts()
    {
        return SystemUser::whereNull('customer_id')
            ->whereHas('sites', fn ($q) => $q->whereDoesntHave('preview'))
            ->with(['sites' => fn ($q) => $q->orderBy('domain')])
            ->orderBy('name')
            ->get();
    }

    private function fillInfo(): void
    {
        $c = $this->customer();
        $this->company = (string) $c->company;
        $this->name = $c->name;
        $this->email = $c->email;
        $this->phone = (string) $c->phone;
        $this->notes = (string) $c->notes;
        $this->packageId = $c->package_id;
    }

    private function customer(): ?Customer
    {
        return Customer::with('package', 'user')->find($this->customerId);
    }
}
