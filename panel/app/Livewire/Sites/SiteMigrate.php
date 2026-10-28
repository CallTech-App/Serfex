<?php

namespace App\Livewire\Sites;

use App\Models\BackupRestore;
use App\Models\Customer;
use App\Models\Package;
use App\Models\SiteMigration;
use App\Models\SystemUser;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Backups\Backups;
use App\Services\Customers\Customers;
use App\Services\Customers\Quotas;
use App\Services\Dns\ZoneTemplate;
use App\Services\Migrations\SiteMigrations;
use App\Support\Hostnames;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Başka sunucudan taşı: an account archive from cPanel is uploaded (or
 * picked from the import folder), or a Plesk server is connected to over
 * SSH and one of its subscriptions copied here; the archive is read, the
 * administrator sees what it holds and what will not come, picks the
 * sites, the parts and the customer, and starts the move. Progress and the report (new passwords,
 * what to do next) stay on the page. Administrators only.
 */
#[Layout('components.layouts.app')]
class SiteMigrate extends Component
{
    #[Url(as: 'migration', except: null)]
    public ?int $migrationId = null;

    public string $from = 'upload';

    /** @var list<string> */
    public array $sites = [];

    /** @var list<string> */
    public array $parts = [];

    public string $customerMode = 'new';

    public string $customerName = '';

    public string $customerEmail = '';

    public ?int $packageId = null;

    public ?int $customerId = null;

    public bool $confirming = false;

    public ?string $error = null;

    public string $pleskHost = '';

    public int $pleskPort = 22;

    public string $pleskUser = 'root';

    /** "key" (Serfex's own key) or "password". */
    public string $pleskAuth = 'key';

    public string $pleskPassword = '';

    /** @var array<string, mixed>|null The server connected to, with its subscriptions. */
    public ?array $pleskServer = null;

    public ?int $pleskSubscription = null;

    /** The migration whose starting choices are on the page. */
    public ?int $chosenFor = null;

    public function mount(): void
    {
        if ($this->migrationId === null) {
            $this->migrationId = SiteMigration::whereIn('status', [SiteMigration::READY, ...SiteMigration::RUNNING])->latest('id')->value('id');
        }
        if (($m = $this->migration()) !== null && $m->status === SiteMigration::READY) {
            $this->choose($m);
        }
    }

    private function migration(): ?SiteMigration
    {
        return $this->migrationId !== null ? SiteMigration::find($this->migrationId) : null;
    }

    /**
     * The starting choices for a migration just read: every site that is
     * free here, everything that can come, a new customer from the
     * account's contact address.
     */
    private function choose(SiteMigration $m): void
    {
        $this->chosenFor = $m->id;
        $review = app(SiteMigrations::class)->review($m);
        $this->sites = collect($m->plan['sites'] ?? [])->pluck('domain')->filter(fn ($d) => ($review['taken'][$d] ?? null) === null)->values()->all();
        $this->parts = array_values(array_filter(array_keys(SiteMigrations::PARTS), fn ($p) => ! ($p === 'databases' && ! $review['mysql']) && ! ($p === 'mail' && ! $review['mail'])));
        $account = $review['account'];
        $this->customerMode = $account?->customer_id !== null ? 'existing' : 'new';
        $this->customerId = $account?->customer_id;
        $this->customerName = (string) ($m->plan['main_domain'] ?? '');
        $this->customerEmail = (string) ($m->plan['contact'] ?? '');
        $this->packageId = Package::where('name', $m->plan['package'] ?? '')->value('id') ?? Package::orderBy('id')->value('id');
    }

    /**
     * A new upload id for the browser to send an archive under.
     */
    #[Renderless]
    public function newUpload(): string
    {
        return 'up-'.Str::lower(Str::random(20));
    }

    public function inspectUpload(string $upload, SiteMigrations $migrations): void
    {
        if (preg_match('/^up-[a-z0-9]{20}$/', $upload)) {
            $this->read($migrations->inspect(null, $upload, auth()->user()));
        }
    }

    public function inspectFile(string $file, SiteMigrations $migrations): void
    {
        $this->read($migrations->inspect($file, null, auth()->user()));
    }

    private function read(SiteMigration|string $result): void
    {
        if (is_string($result)) {
            $this->error = $result;

            return;
        }
        $this->error = null;
        $this->migrationId = $result->id;
        $this->choose($result);
    }

    /**
     * Signs in to the Plesk server and lists its subscriptions.
     */
    public function connectPlesk(SiteMigrations $migrations): void
    {
        $this->resetValidation();
        $this->error = null;
        $this->validate([
            'pleskHost' => ['required', 'max:253', function ($attr, $value, $fail) {
                if (filter_var($value, FILTER_VALIDATE_IP) === false && ! Hostnames::isValidDomain(Hostnames::normalize($value))) {
                    $fail(__('Geçerli bir sunucu adı ya da IP adresi girin.'));
                }
            }],
            'pleskPort' => ['required', 'integer', 'between:1,65535'],
            'pleskUser' => ['required', 'regex:/^[a-z_][a-z0-9_.-]{0,31}$/'],
            'pleskAuth' => ['required', Rule::in(['key', 'password'])],
            'pleskPassword' => [$this->pleskAuth === 'password' ? 'required' : 'nullable', 'max:1024'],
        ], ['pleskPassword.required' => __('Parolayı girin.')]);
        $server = $migrations->pleskConnect($this->pleskConnection());
        if (is_string($server)) {
            $this->error = $server;

            return;
        }
        $this->pleskServer = $server;
        $this->pleskSubscription = count($server['subscriptions']) === 1 ? $server['subscriptions'][0]['id'] : null;
    }

    /**
     * @return array{host: string, port: int, user: string, password: string, host_key: string}
     */
    private function pleskConnection(): array
    {
        return [
            'host' => filter_var(trim($this->pleskHost), FILTER_VALIDATE_IP) !== false ? trim($this->pleskHost) : Hostnames::normalize($this->pleskHost),
            'port' => $this->pleskPort,
            'user' => $this->pleskUser,
            'password' => $this->pleskAuth === 'password' ? $this->pleskPassword : '',
            'host_key' => (string) ($this->pleskServer['host_key'] ?? ''),
        ];
    }

    /**
     * Back to the server's address and sign-in.
     */
    public function disconnectPlesk(): void
    {
        $this->reset('pleskServer', 'pleskSubscription', 'error');
    }

    /**
     * Starts copying the chosen subscription here.
     */
    public function pullPlesk(SiteMigrations $migrations): void
    {
        $this->error = null;
        $sub = collect($this->pleskServer['subscriptions'] ?? [])->firstWhere('id', $this->pleskSubscription);
        if ($sub === null) {
            $this->error = __('Taşınacak aboneliği seçin.');

            return;
        }
        $result = $migrations->pleskPull($this->pleskConnection(), $sub, auth()->user());
        if (is_string($result)) {
            $this->error = $result;

            return;
        }
        $this->reset('pleskServer', 'pleskSubscription', 'pleskPassword');
        $this->migrationId = $result->id;
    }

    public function confirmStart(): void
    {
        $this->resetValidation();
        $this->error = null;
        $m = $this->migration();
        if ($m === null || $m->status !== SiteMigration::READY) {
            return;
        }
        $this->validate(...$this->rules($m));
        $this->confirming = true;
    }

    /**
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    private function rules(SiteMigration $m): array
    {
        $user = (string) ($m->plan['user'] ?? '');
        $rules = [
            'sites' => ['required', 'array', 'min:1'],
            'parts' => ['array'],
            'customerMode' => ['required', Rule::in(['new', 'existing', 'none'])],
        ];
        if ($this->customerMode === 'new') {
            $rules += [
                'customerName' => ['required', 'max:100'],
                'customerEmail' => ['required', 'email', 'max:190', Rule::unique('users', 'email')],
                'packageId' => ['required', Rule::exists('packages', 'id')],
            ];
            $rules['customerMode'][] = function ($attr, $value, $fail) use ($user) {
                if (Customer::withTrashed()->where('username', $user)->exists() || SystemUser::where('name', $user)->whereNotNull('customer_id')->exists()) {
                    $fail(__('Bu sistem kullanıcısı başka bir müşteriye ait.'));
                }
            };
        } elseif ($this->customerMode === 'existing') {
            $rules['customerId'] = ['required', Rule::exists('customers', 'id')->whereNull('deleted_at')];
        }

        return [$rules, [
            'sites.required' => __('Taşınacak en az bir site seçin.'),
            'sites.min' => __('Taşınacak en az bir site seçin.'),
            'customerEmail.unique' => __('Bu e-posta adresiyle bir panel hesabı zaten var.'),
        ]];
    }

    public function start(SiteMigrations $migrations, Customers $customers): void
    {
        $m = $this->migration();
        if ($m === null || $m->status !== SiteMigration::READY) {
            return;
        }
        $this->validate(...$this->rules($m));
        $customerId = null;
        $invite = null;
        if ($this->customerMode === 'existing') {
            $customerId = $this->customerId;
        } elseif ($this->customerMode === 'new') {
            if ($migrations->busy()) {
                $this->confirming = false;
                $this->error = __(Backups::BUSY);

                return;
            }
            [$customer, $invite] = $customers->create([
                'name' => trim($this->customerName),
                'email' => mb_strtolower(trim($this->customerEmail)),
                'username' => (string) $m->plan['user'],
                'package_id' => $this->packageId,
            ]);
            $customerId = $customer->id;
            Quotas::forget();
        }
        if ($invite !== null) {
            $m->update(['secrets' => [...($m->secrets ?? []), 'invite' => route('invitation', $invite)]]);
        }
        $why = $migrations->start($m, ['sites' => $this->sites, 'parts' => $this->parts, 'customer_id' => $customerId], auth()->user());
        $this->confirming = false;
        $this->error = $why;
    }

    public function discard(SiteMigrations $migrations): void
    {
        if (($m = $this->migration()) !== null) {
            $migrations->discard($m);
        }
        $this->fresh();
    }

    /**
     * Back to choosing an archive.
     */
    public function fresh(): void
    {
        $this->reset('migrationId', 'sites', 'parts', 'confirming', 'error', 'chosenFor');
    }

    public function forgetSecrets(): void
    {
        $m = $this->migration();
        if ($m !== null && $m->isFinished()) {
            $m->update(['secrets' => null]);
        }
    }

    /**
     * A chunk of a progress log of the migration on the page.
     *
     * @return array<string, mixed>|null
     */
    public function readRun(string $run, int $offset = 0): ?array
    {
        $m = $this->migration();
        if ($m === null || ! in_array($run, app(SiteMigrations::class)->runs($m), true)) {
            return null;
        }
        $restore = BackupRestore::where('run', $run)->first();
        $running = $restore !== null ? $restore->isRunning() : in_array($m->status, ['pulling', 'queued', 'converting'], true);
        try {
            $chunk = app(AgentClient::class)->call('backup.run.read', ['run' => $run, 'offset' => max(0, $offset)]);
        } catch (AgentException) {
            return ['data' => '', 'offset' => $offset, 'more' => false, 'done' => ! $running, 'error' => __('Sunucu ajanına ulaşılamıyor.')];
        }
        $next = (int) ($chunk['offset'] ?? 0);

        return ['data' => (string) ($chunk['data'] ?? ''), 'offset' => $next, 'more' => $next < (int) ($chunk['size'] ?? 0), 'done' => ! $running, 'error' => null];
    }

    public function render(SiteMigrations $migrations): View
    {
        $m = $this->migration();
        if ($m !== null && $m->status === SiteMigration::READY && $this->chosenFor !== $m->id) {
            // A Plesk subscription just copied.
            $this->choose($m);
        }
        $data = [
            'migration' => $m,
            'history' => SiteMigration::whereIn('status', ['succeeded', 'partial', 'failed'])->latest('id')->limit(10)->get(),
        ];
        if ($m === null) {
            if ($this->from === 'plesk' && $this->pleskServer === null && $this->pleskAuth === 'key') {
                $data['publicKey'] = $migrations->publicKey();
            }
            if ($this->from === 'folder') {
                try {
                    $data['files'] = $migrations->files();
                } catch (AgentException $e) {
                    $data['files'] = [];
                    $data['filesError'] = $e->reason ?: __('Sunucu ajanına ulaşılamıyor.');
                }
            }
        } elseif ($m->status === SiteMigration::READY) {
            $data['review'] = $migrations->review($m);
            $data['packages'] = Package::orderBy('id')->get();
            $data['customers'] = Customer::orderBy('name')->get();
        } else {
            $data['runs'] = $migrations->runs($m);
            $data['nameservers'] = app(ZoneTemplate::class)->nameservers((string) ($m->plan['main_domain'] ?? ''));
        }

        return view('livewire.sites.migrate', $data)->title(__('Başka sunucudan taşı · Serfex'));
    }
}
