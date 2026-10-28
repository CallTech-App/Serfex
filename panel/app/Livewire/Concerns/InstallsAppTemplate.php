<?php

namespace App\Livewire\Concerns;

use App\Models\AppTemplate;
use App\Models\LaravelApp;
use App\Models\Site;
use App\Services\Customers\Quotas;
use App\Services\Databases\Databases;
use App\Services\Laravel\AppTemplates;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * The application template part of an install form (Site ekle, Laravel
 * install, a template's "Siteye kur"): the template, its questions and
 * the first administrator. The component uses WithFileUploads.
 */
trait InstallsAppTemplate
{
    /** The picked template's id, "" for none. */
    public string $templateId = '';

    /** @var array<string, string> question key => answer */
    public array $answers = [];

    /** @var array<string, TemporaryUploadedFile|null> question key => upload */
    public array $uploads = [];

    public string $adminName = '';

    public string $adminEmail = '';

    /** The largest file a question takes, in kilobytes. */
    protected int $templateFileKb = 256;

    /**
     * The templates the signed-in user may install, by name.
     *
     * @return \Illuminate\Support\Collection<int, AppTemplate>
     */
    protected function installableTemplates()
    {
        return AppTemplate::installableBy(auth()->user())->orderByRaw('lower(name)')->get();
    }

    protected function pickedTemplate(): ?AppTemplate
    {
        return $this->templateId === '' ? null : AppTemplate::installableBy(auth()->user())->find((int) $this->templateId);
    }

    /**
     * Fills the answers with the template's defaults (placeholders of the
     * site and customer filled in) and the administrator with the
     * customer.
     */
    protected function prepareTemplateForm(?Site $site, ?string $customerName = null, ?string $customerEmail = null, ?string $domain = null): void
    {
        $this->answers = [];
        $this->uploads = [];
        $template = $this->pickedTemplate();
        if ($template === null) {
            return;
        }
        $customer = $site?->customer();
        $name = $customerName ?? $customer?->displayName() ?? '';
        $email = $customerEmail ?? (string) ($customer?->email ?? '');
        $domain ??= $site?->domain ?? '';
        $values = ['site.domain' => $domain, 'site.url' => $domain !== '' ? 'https://'.$domain : '', 'customer.name' => $name, 'customer.email' => $email];
        foreach ($template->questions() as $q) {
            if ($q['type'] === 'file') {
                $this->uploads[$q['key']] = null;
            } else {
                $this->answers[$q['key']] = $q['secret'] ? '' : AppTemplates::fill($q['default'], $values);
            }
        }
        $this->adminName = $name;
        $this->adminEmail = $email;
    }

    /**
     * Validation rules of the template part.
     *
     * @return array<string, mixed>
     */
    protected function templateRules(AppTemplate $template): array
    {
        $rules = [];
        foreach ($template->questions() as $q) {
            $rules[$q['type'] === 'file' ? "uploads.{$q['key']}" : "answers.{$q['key']}"] = $q['type'] === 'file'
                ? [$q['required'] ? 'required' : 'nullable', 'file', 'max:'.$this->templateFileKb]
                : [$q['required'] ? 'required' : 'nullable', 'string', 'max:1000'];
        }
        if ($template->create_admin) {
            $rules['adminName'] = ['required', 'string', 'max:100'];
            $rules['adminEmail'] = ['required', 'email', 'max:200'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function templateAttributes(AppTemplate $template): array
    {
        $names = ['adminName' => __('yönetici adı'), 'adminEmail' => __('yönetici e-postası')];
        foreach ($template->questions() as $q) {
            $names[($q['type'] === 'file' ? 'uploads.' : 'answers.').$q['key']] = mb_strtolower($q['label'] ?: $q['key']);
        }

        return $names;
    }

    /**
     * Why the template cannot be installed on $site now, or null.
     */
    protected function templateRefusal(AppTemplate $template, Site $site): ?string
    {
        $quotas = app(Quotas::class);
        if (($refusal = $quotas->refusal($site->customer(), 'laravel_apps')) !== null) {
            return $refusal;
        }
        if (in_array($template->database, ['mysql', 'postgresql'], true) && ($refusal = $quotas->refusal($site->customer(), 'databases')) !== null) {
            return $refusal;
        }
        if ($template->isPackage() ? ! $template->latest_package && ! $template->package_url : ! $template->repository) {
            return __('Şablonun kaynağı eksik; şablonun sayfasından tamamlayın.');
        }

        return null;
    }

    /**
     * Starts the install (the template was validated) and leaves the
     * first administrator's password for the application's page, shown
     * there once.
     */
    protected function startTemplateInstall(AppTemplate $template, Site $site): LaravelApp
    {
        $answers = [];
        foreach ($template->questions() as $q) {
            if ($q['type'] === 'text') {
                $answers[$q['key']] = trim((string) ($this->answers[$q['key']] ?? ''));
            }
        }
        $files = [];
        foreach ($this->uploads as $key => $upload) {
            if ($upload instanceof TemporaryUploadedFile) {
                $files[$key] = ['name' => $upload->getClientOriginalName(), 'content' => (string) file_get_contents($upload->getRealPath())];
            }
        }
        $admin = $template->create_admin
            ? ['name' => trim($this->adminName), 'email' => trim($this->adminEmail), 'password' => self::adminPassword()]
            : null;
        $app = app(AppTemplates::class)->install($template, $site, $answers, $files, $admin, auth()->user());
        if ($admin !== null) {
            session()->flash('template_admin', Crypt::encrypt($admin + ['template' => $template->name, 'app' => $app->id]));
        }

        return $app;
    }

    /**
     * A password that survives being read out: 20 letters and digits in
     * groups of five.
     */
    public static function adminPassword(): string
    {
        return implode('-', str_split(Str::password(20, symbols: false), 5));
    }

    /**
     * What the form says the template brings, in one line.
     */
    protected function templateSummary(AppTemplate $template, ?Site $site): string
    {
        $parts = [];
        if ($template->php_version) {
            $parts[] = 'PHP '.$template->php_version;
        }
        $parts[] = match ($template->database) {
            'mysql', 'postgresql' => __('yeni :db veritabanı', ['db' => $template->database === 'mysql' ? 'MySQL' : 'PostgreSQL'])
                .($site ? ' ('.Databases::prefix($site->systemUser).$template->databaseSuffix().')' : ''),
            'sqlite' => __('SQLite'),
            default => __('veritabanı yok'),
        };
        if (! $template->isPackage() && $template->frontend) {
            $parts[] = __('ön yüz derlemesi');
        }
        if ($template->worker) {
            $parts[] = __('kuyruk işçisi');
        }
        if ($template->scheduler) {
            $parts[] = __('zamanlayıcı');
        }

        return implode(', ', $parts);
    }
}
