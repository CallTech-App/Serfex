<?php

namespace App\Livewire\Customers;

use App\Jobs\ApplySitePhp;
use App\Models\Customer;
use App\Models\Package;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Müşteriler ve Paketler: the customers with their usage against their
 * package, and the packages themselves.
 */
#[Layout('components.layouts.app')]
#[Title('Müşteriler ve Paketler · Servik')]
class CustomerIndex extends Component
{
    use WithPagination;

    /** musteriler or paketler. */
    #[Url(as: 'sekme', except: 'musteriler')]
    public string $tab = 'musteriler';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** @var list<int> */
    public array $selected = [];

    // Müşteri ekle
    public bool $creating = false;

    public string $company = '';

    public string $name = '';

    public string $email = '';

    public string $phone = '';

    public string $username = '';

    /** True once the user typed their own account name. */
    public bool $usernameTouched = false;

    public ?int $packageId = null;

    /** Shown once after a customer is added: their invitation link. */
    public ?string $inviteLink = null;

    public ?int $invitedId = null;

    // Paket ekle / düzenle
    public bool $editingPackage = false;

    public ?int $packageEditId = null;

    public string $pkgName = '';

    /** @var array<string, string> Limits as typed; disk and traffic in GB, '' for unlimited. */
    public array $limits = [];

    /** @var list<string> */
    public array $features = [];

    public bool $allPhp = true;

    /** @var list<string> */
    public array $pkgPhp = [];

    public bool $pkgDefault = false;

    /** Most memory_limit (MB) the package's sites get; '' for no limit. */
    public string $pkgPhpMemory = '';

    /** @var list<string> */
    public array $installedPhp = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedTab(): void
    {
        $this->selected = [];
        $this->resetPage();
    }

    public function openCreate(): void
    {
        $this->reset('company', 'name', 'email', 'phone', 'username', 'usernameTouched');
        $this->packageId = Package::default()?->id;
        $this->resetValidation();
        $this->creating = true;
    }

    public function updatedCompany(): void
    {
        $this->suggest();
    }

    public function updatedName(): void
    {
        $this->suggest();
    }

    public function updatedUsername(): void
    {
        $this->usernameTouched = true;
    }

    public function create(Customers $customers): void
    {
        $this->company = trim($this->company);
        $this->name = trim($this->name);
        $this->email = mb_strtolower(trim($this->email));
        $this->username = trim($this->username);
        $this->validate([
            'company' => ['nullable', 'max:120'],
            'name' => ['required', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'max:40', 'regex:/^[0-9+() .-]+$/'],
            'username' => ['required', 'max:32', 'regex:'.Hostnames::SYSTEM_USER_PATTERN, Rule::notIn(Hostnames::RESERVED_USERS), Rule::unique('customers', 'username'),
                function ($attr, $value, $fail) {
                    $account = SystemUser::where('name', $value)->first();
                    if ($account !== null && $account->customer_id !== null) {
                        $fail('Bu sistem kullanıcısı başka bir müşteriye ait.');
                    }
                }],
            'packageId' => ['required', Rule::exists('packages', 'id')],
        ], [
            'email.unique' => 'Bu e-posta adresiyle bir panel hesabı zaten var.',
            'username.regex' => 'Küçük harfle başlamalı; yalnızca a-z, 0-9, - ve _ içerebilir (en fazla 32).',
            'username.unique' => 'Bu sistem kullanıcısı başka bir müşteriye ait.',
            'username.not_in' => 'Bu ad sistem tarafından kullanılıyor.',
            'phone.regex' => 'Telefon numarası geçersiz.',
        ], [
            'company' => 'firma adı', 'name' => 'yetkili kişi', 'email' => 'e-posta', 'phone' => 'telefon',
            'username' => 'sistem kullanıcısı', 'packageId' => 'paket',
        ]);

        [$customer, $invite] = $customers->create([
            'company' => $this->company ?: null,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => trim($this->phone) ?: null,
            'username' => $this->username,
            'package_id' => $this->packageId,
        ]);
        Quotas::forget();
        $this->creating = false;
        $this->invitedId = $customer->id;
        $this->inviteLink = route('invitation', $invite);
    }

    public function closeInvite(): void
    {
        $this->reset('inviteLink', 'invitedId');
    }

    public function suspendSelected(Customers $customers): void
    {
        $done = [];
        foreach (Customer::whereIn('id', $this->selected)->whereNull('suspended_at')->get() as $customer) {
            $customers->suspend($customer);
            $done[] = $customer->id;
        }
        $this->selected = [];
        if ($done !== []) {
            $this->dispatch('toast',
                message: count($done) === 1 ? 'Müşteri askıya alındı.' : count($done).' müşteri askıya alındı.',
                source: $this->getId(),
                undo: ['method' => 'undoSuspend', 'args' => [$done]],
            );
        }
    }

    /**
     * @param  list<int>  $ids
     */
    public function undoSuspend(array $ids, Customers $customers): void
    {
        foreach (Customer::whereIn('id', $ids)->get() as $customer) {
            $customers->unsuspend($customer);
        }
    }

    public function restore(int $id, Customers $customers): void
    {
        $customer = Customer::onlyTrashed()->find($id);
        if ($customer !== null && $customers->restore($customer)) {
            Quotas::forget();
            $this->dispatch('toast', message: $customer->displayName().' geri alındı.');
        }
    }

    public function openPackage(AgentClient $agent, ?int $id = null): void
    {
        $package = $id ? Package::find($id) : null;
        $this->resetValidation();
        $this->packageEditId = $package?->id;
        $this->pkgName = $package->name ?? '';
        $this->limits = [];
        foreach (Package::LIMITS as $key => [$column]) {
            $value = $package?->{$column};
            $this->limits[$key] = match (true) {
                $value === null => '',
                $key === 'disk' => rtrim(rtrim(number_format($value / 1024, 2, '.', ''), '0'), '.'),
                default => (string) $value,
            };
        }
        $this->features = $package
            ? array_keys(array_filter(Package::FEATURES, fn ($f) => $package->{$f[0]}))
            : ['laravel', 'mail', 'dns'];
        $this->allPhp = $package?->php_versions === null;
        $this->pkgPhp = $package->php_versions ?? [];
        $this->pkgDefault = (bool) $package?->is_default;
        $this->pkgPhpMemory = $package ? (string) $package->max_php_memory_mb : '512';
        try {
            $this->installedPhp = array_values($agent->call('php.versions') ?? []);
        } catch (AgentException) {
            $this->installedPhp = [];
        }
        // Versions the package allows that are no longer installed stay listed.
        $this->installedPhp = array_values(array_unique([...$this->installedPhp, ...$this->pkgPhp]));
        sort($this->installedPhp);
        $this->editingPackage = true;
    }

    public function savePackage(): void
    {
        $this->pkgName = trim($this->pkgName);
        $this->limits = array_map(fn ($v) => str_replace(',', '.', trim((string) $v)), $this->limits);
        $rules = ['pkgName' => ['required', 'max:60', Rule::unique('packages', 'name')->ignore($this->packageEditId)]];
        foreach (array_keys(Package::LIMITS) as $key) {
            $rules["limits.{$key}"] = in_array($key, ['disk', 'traffic'], true)
                ? ['nullable', 'numeric', 'min:0', 'max:1000000']
                : ['nullable', 'integer', 'min:0', 'max:100000'];
        }
        $rules['features'] = ['array'];
        $rules['features.*'] = [Rule::in(array_keys(Package::FEATURES))];
        $rules['pkgPhp'] = ['array', $this->allPhp ? 'nullable' : 'min:1'];
        $rules['pkgPhp.*'] = ['regex:/^\d+\.\d+$/'];
        $this->pkgPhpMemory = trim($this->pkgPhpMemory);
        $rules['pkgPhpMemory'] = ['nullable', 'integer', 'min:32', 'max:65536'];
        $this->validate($rules, [
            'pkgName.unique' => 'Bu adda bir paket zaten var.',
            'pkgPhp.min' => 'En az bir PHP sürümü seçin ya da tümüne izin verin.',
        ], array_merge(['pkgName' => 'paket adı', 'pkgPhpMemory' => 'PHP bellek sınırı'], array_combine(
            array_map(fn ($k) => "limits.{$k}", array_keys(Package::LIMITS)),
            array_map(fn ($l) => mb_strtolower($l[1]), Package::LIMITS),
        )));

        $data = ['name' => $this->pkgName, 'is_default' => $this->pkgDefault];
        foreach (Package::LIMITS as $key => [$column]) {
            $value = $this->limits[$key] ?? '';
            $data[$column] = $value === '' ? null : (int) round($key === 'disk' ? (float) $value * 1024 : (float) $value);
        }
        foreach (Package::FEATURES as $key => [$column]) {
            $data[$column] = in_array($key, $this->features, true);
        }
        // Commands and Tinker are part of the Laravel toolkit.
        $data['allow_shell'] = $data['allow_shell'] && $data['allow_laravel'];
        $data['php_versions'] = $this->allPhp ? null : array_values($this->pkgPhp);
        $data['max_php_memory_mb'] = $this->pkgPhpMemory === '' ? null : (int) $this->pkgPhpMemory;

        $package = $this->packageEditId ? Package::findOrFail($this->packageEditId) : new Package;
        if ($package->exists && $package->is_default && ! $this->pkgDefault) {
            // There is always a default package; pick another one to change it.
            $data['is_default'] = true;
        }
        $package->fill($data);
        // Sites take the package's PHP limits with their next pool write.
        $phpChanged = $package->exists && $package->isDirty(['allow_php_settings', 'max_php_memory_mb']);
        $package->save();
        if ($phpChanged) {
            foreach ($package->customers as $customer) {
                ApplySitePhp::forCustomer($customer);
            }
        }
        if ($package->is_default) {
            Package::whereKeyNot($package->id)->update(['is_default' => false]);
        }
        Quotas::forget();
        $this->editingPackage = false;
        $this->dispatch('toast', message: $this->packageEditId ? 'Paket kaydedildi; müşterilerine hemen uygulanır.' : 'Paket eklendi.');
    }

    public function deletePackage(int $id): void
    {
        $package = Package::withCount('customers')->find($id);
        if ($package === null) {
            return;
        }
        if ($package->customers_count > 0 || $package->is_default) {
            $this->dispatch('toast', message: $package->is_default
                ? 'Varsayılan paket silinemez. Önce başka bir paketi varsayılan yapın.'
                : 'Bu paketi kullanan müşteriler var. Önce onları başka bir pakete taşıyın.');

            return;
        }
        $attributes = $package->only($package->getFillable());
        $package->delete();
        $this->dispatch('toast', message: "{$attributes['name']} paketi silindi.", source: $this->getId(),
            undo: ['method' => 'undoDeletePackage', 'args' => [$attributes]]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function undoDeletePackage(array $attributes): void
    {
        $attributes = array_intersect_key($attributes, array_flip((new Package)->getFillable()));
        if (isset($attributes['name']) && Package::where('name', $attributes['name'])->doesntExist()) {
            Package::create(['is_default' => false] + $attributes);
        }
    }

    public function render(Quotas $quotas): View
    {
        $customers = Customer::withTrashed()
            ->with('package', 'user')
            ->withCount('sites')
            ->when($this->search !== '', fn ($q) => $q->search($this->search))
            ->orderByRaw('deleted_at is not null')
            ->orderByRaw('lower(coalesce(company, name))')
            ->paginate(25);
        $reports = $customers->getCollection()->mapWithKeys(fn (Customer $c) => [$c->id => $quotas->report($c)]);

        return view('livewire.customers.index', [
            'customers' => $customers,
            'reports' => $reports,
            'packages' => Package::withCount('customers')->orderBy('id')->get(),
            'total' => Customer::count(),
        ]);
    }

    /**
     * Proposes an account name from the company (or the person) until the
     * user types their own.
     */
    private function suggest(): void
    {
        if ($this->usernameTouched) {
            return;
        }
        $from = trim($this->company) ?: trim($this->name);
        $this->username = $from === '' ? '' : app(Customers::class)->suggestUsername($from);
    }
}
