<?php

namespace App\Http\Controllers\Api\Panel;

use App\Models\Customer;
use App\Models\Package;
use App\Models\SystemUser;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Support\Hostnames;
use App\Support\Locales;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CustomersController extends ApiController
{
    public function index(): JsonResponse
    {
        $customers = Customer::with('package')->orderBy('id')->get();

        return response()->json(['data' => $customers->map(fn (Customer $c) => $this->customer($c))->values()]);
    }

    public function show(int $customer, Quotas $quotas): JsonResponse
    {
        $customer = $this->findCustomer($customer);
        $usage = [];
        foreach ($quotas->report($customer->load('package')) as $kind => $row) {
            $usage[$kind] = ['used' => $row['used'], 'limit' => $row['limit'], 'level' => $row['level'], 'text' => $row['text']];
        }

        return response()->json(['data' => [
            ...$this->customer($customer),
            'sites' => $customer->sites()->orderBy('domain')->pluck('domain'),
            'usage' => $usage,
        ]]);
    }

    /**
     * POST /api/v1/customers {name, email, username, package_id?, company?, phone?}.
     * The answer carries the invitation link the customer sets a password with.
     */
    public function store(Request $request, Customers $customers): JsonResponse
    {
        $request->merge(['email' => mb_strtolower(trim((string) $request->input('email')))]);
        $data = $request->validate([
            'company' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
            'phone' => ['nullable', 'string', 'max:40', 'regex:/^[0-9+() .-]+$/'],
            'username' => ['required', 'string', 'max:32', 'regex:'.Hostnames::SYSTEM_USER_PATTERN, Rule::notIn(Hostnames::RESERVED_USERS), Rule::unique('customers', 'username'),
                function ($attr, $value, $fail) {
                    if (SystemUser::where('name', $value)->whereNotNull('customer_id')->exists()) {
                        $fail(__('Bu sistem kullanıcısı başka bir müşteriye ait.'));
                    }
                }],
            'package_id' => ['nullable', 'integer', Rule::exists('packages', 'id')],
        ]);
        $package = $data['package_id'] ?? Package::default()?->id;
        if ($package === null) {
            return $this->refuse(__('Henüz paket yok; önce panelden bir paket oluşturun.'));
        }
        [$customer, $invite] = $customers->create([...$data, 'package_id' => $package]);
        Quotas::forget();
        $this->audit($request, __('Müşteriler ve Paketler'), __(':name müşterisi eklendi.', ['name' => $customer->displayName()]), route('customers.show', $customer, false));

        return response()->json(['data' => [...$this->customer($customer->load('package')), 'invitation_url' => route('invitation', $invite)]], 201);
    }

    public function suspend(Request $request, int $customer, Customers $customers): JsonResponse
    {
        $customer = $this->findCustomer($customer);
        $reason = $request->validate(['reason' => ['nullable', 'string', 'max:200']])['reason'] ?? null;
        if ($customer->suspended_at === null) {
            $customers->suspend($customer, $reason);
            $this->audit($request, __('Müşteriler ve Paketler'), __(':name askıya alındı.', ['name' => $customer->displayName()]), route('customers.show', $customer, false));
        }

        return response()->json(['data' => $this->customer($customer->fresh('package'))]);
    }

    public function unsuspend(Request $request, int $customer, Customers $customers): JsonResponse
    {
        $customer = $this->findCustomer($customer);
        if ($customer->suspended_at !== null) {
            $customers->unsuspend($customer);
            $this->audit($request, __('Müşteriler ve Paketler'), __(':name yeniden etkin.', ['name' => $customer->displayName()]), route('customers.show', $customer, false));
        }

        return response()->json(['data' => $this->customer($customer->fresh('package'))]);
    }

    /**
     * @return array<string, mixed>
     */
    private function customer(Customer $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'company' => $c->company,
            'email' => $c->email,
            'phone' => $c->phone,
            'username' => $c->username,
            'package' => $c->package ? ['id' => $c->package->id, 'name' => Locales::label($c->package->name)] : null,
            'suspended' => $c->suspended_at !== null,
            'suspended_at' => $c->suspended_at?->toIso8601String(),
            'suspend_reason' => $c->suspend_reason,
            'created_at' => $c->created_at?->toIso8601String(),
        ];
    }

    private function findCustomer(int $id): Customer
    {
        return Customer::find($id) ?? $this->missing(__(':id numaralı bir müşteri yok.', ['id' => $id]));
    }
}
