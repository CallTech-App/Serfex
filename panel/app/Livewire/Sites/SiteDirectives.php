<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Sites\SiteDomains;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Web sunucusu yönergeleri: the administrator's own lines for the site's
 * Nginx server block (and its Apache vhost when the site runs behind
 * Apache). The web server tests them before they are used; a refusal
 * changes nothing and shows the server's own message. Administrators
 * only: these lines run with the web server's rights.
 */
#[Layout('components.layouts.app')]
class SiteDirectives extends Component
{
    public const MAX_BYTES = 64 * 1024;

    public int $siteId;

    public string $nginx = '';

    public string $apache = '';

    public function mount(int $site): void
    {
        $model = Site::findOrFail($site);
        $this->siteId = $model->id;
        $this->nginx = (string) $model->nginx_directives;
        $this->apache = (string) $model->apache_directives;
    }

    private function site(): Site
    {
        return Site::with('systemUser.customer')->findOrFail($this->siteId);
    }

    public function save(SiteDomains $domains): void
    {
        $this->resetValidation();
        $site = $this->site();
        $values = ['nginx_directives' => self::clean($this->nginx), 'apache_directives' => self::clean($this->apache)];
        foreach (['nginx_directives' => 'nginx', 'apache_directives' => 'apache'] as $column => $field) {
            if (strlen((string) $values[$column]) > self::MAX_BYTES) {
                $this->addError($field, 'En fazla 64 KB yazılabilir.');
            }
        }
        if ($this->getErrorBag()->isNotEmpty()) {
            return;
        }
        $before = $site->only(array_keys($values));
        if ($before === $values) {
            $this->dispatch('toast', message: 'Değişiklik yok.');

            return;
        }
        try {
            $domains->change($site, fn () => $site->update($values));
        } catch (AgentException $e) {
            $field = $site->web_server === Site::APACHE && str_starts_with($e->reason, 'apache') ? 'apache' : 'nginx';
            $this->addError($field, $e->forPeople());

            return;
        }
        $token = Str::random(24);
        Cache::put('site-directives-undo:'.$token, ['site_id' => $site->id, ...$before], SiteDomains::UNDO_SECONDS);
        $this->dispatch('toast', message: 'Yönergeler denendi ve uygulandı.', source: $this->getId(), undo: ['method' => 'undo', 'args' => [$token]]);
    }

    public function undo(SiteDomains $domains, string $token): void
    {
        $saved = Cache::pull('site-directives-undo:'.$token);
        $site = $this->site();
        if ($saved === null || $saved['site_id'] !== $site->id) {
            return;
        }
        $values = ['nginx_directives' => $saved['nginx_directives'], 'apache_directives' => $saved['apache_directives']];
        try {
            $domains->change($site, fn () => $site->update($values));
        } catch (AgentException $e) {
            $this->dispatch('toast', message: 'Geri alınamadı: '.$e->forPeople());

            return;
        }
        $this->nginx = (string) $values['nginx_directives'];
        $this->apache = (string) $values['apache_directives'];
        $this->resetValidation();
        $this->dispatch('toast', message: 'Yönergeler önceki hâline döndü.');
    }

    private static function clean(string $text): ?string
    {
        $text = trim(str_replace("\r\n", "\n", $text));

        return $text === '' ? null : $text;
    }

    public function render(): View
    {
        $site = $this->site();

        return view('livewire.sites.directives', ['site' => $site])
            ->title('Web sunucusu yönergeleri · '.$site->domain.' · Servik');
    }
}
