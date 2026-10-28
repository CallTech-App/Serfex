<?php

namespace App\Livewire\Sites;

use App\Livewire\Attributes\ForCustomers;
use App\Livewire\Security\BlocksAddresses;
use App\Models\IpBlock;
use App\Models\Site;
use App\Models\WafExclusion;
use App\Services\Agent\AgentException;
use App\Services\Security\Waf;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * A site's web application firewall: its mode (the server's, off, only
 * record, or block), the rules it turns off because they block its own
 * requests, and what the firewall caught on it. Customers manage their
 * own sites' WAF when their package has site security; blocking an
 * address (the whole server) stays with administrators.
 */
#[Layout('components.layouts.app')]
#[ForCustomers(feature: 'security', except: ['openBlock', 'saveBlock', 'unblock'])]
class SiteWaf extends Component
{
    use BlocksAddresses;

    public int $siteId;

    public const TABS = ['events' => 'Olaylar', 'exceptions' => 'Kural istisnaları'];

    #[Url(except: 'events')]
    public string $tab = 'events';

    /** '' follows the server. */
    public string $mode = '';

    public bool $excluding = false;

    public string $ruleId = '';

    public string $path = '';

    public string $note = '';

    /** Opened from Güvenlik → WAF's "İstisna ekle". */
    #[Url(as: 'rule', except: '')]
    public string $prefillRule = '';

    #[Url(as: 'path', except: '')]
    public string $prefillPath = '';

    public function mount(int $site): void
    {
        $model = Site::visibleTo(auth()->user())->findOrFail($site);
        $this->siteId = $model->id;
        $this->mode = (string) $model->waf_mode;
        if ($this->prefillRule !== '') {
            $this->ruleId = preg_replace('/\D/', '', $this->prefillRule);
            $this->path = $this->prefillPath;
            $this->excluding = true;
            $this->tab = 'exceptions';
            $this->prefillRule = $this->prefillPath = '';
        }
    }

    private function site(): Site
    {
        return Site::visibleTo(auth()->user())->with('systemUser.customer')->findOrFail($this->siteId);
    }

    public function saveMode(Waf $waf): void
    {
        $this->validate(['mode' => ['nullable', Rule::in(['', ...array_keys(Waf::MODES)])]]);
        $site = $this->site();
        $previous = (string) $site->waf_mode;
        if ($previous === $this->mode) {
            return;
        }
        try {
            $waf->setMode($site, $this->mode ?: null);
        } catch (AgentException $e) {
            $this->mode = $previous;
            $this->addError('mode', Waf::message($e));

            return;
        }
        $this->dispatch('toast',
            message: $site->domain.': WAF '.($this->mode === '' ? __('sunucu ayarını izliyor.') : __(':mode kipinde.', ['mode' => mb_strtolower(__(Waf::MODES[$this->mode]))])),
            source: $this->getId(),
            undo: ['method' => 'undoMode', 'args' => [$previous]],
        );
    }

    public function undoMode(string $previous, Waf $waf): void
    {
        if ($previous !== '' && ! array_key_exists($previous, Waf::MODES)) {
            return;
        }
        try {
            $waf->setMode($this->site(), $previous ?: null);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: Waf::message($e));

            return;
        }
        $this->mode = $previous;
    }

    public function openExclude(?int $rule = null, string $path = ''): void
    {
        $this->resetValidation();
        $this->ruleId = $rule ? (string) $rule : '';
        $this->path = $path;
        $this->note = '';
        $this->excluding = true;
    }

    public function saveExclusion(Waf $waf): void
    {
        $this->resetValidation();
        $site = $this->site();
        $rule = (int) preg_replace('/\D/', '', $this->ruleId);
        $path = trim($this->path);
        $this->note = trim($this->note);
        $problems = $waf->problems($site, $rule, $path);
        if (mb_strlen($this->note) > 200) {
            $problems['note'] = __('Not en fazla 200 karakter olabilir.');
        }
        if ($problems !== []) {
            foreach ($problems as $field => $message) {
                $this->addError($field === 'rule' ? 'ruleId' : $field, $message);
            }

            return;
        }
        try {
            $exclusion = $waf->exclude($site, $rule, $path, $this->note ?: null, auth()->user());
        } catch (AgentException $e) {
            $this->addError('ruleId', Waf::message($e));

            return;
        }
        $this->excluding = false;
        $this->dispatch('toast', message: $path === '' ? __('Kural :rule bu sitede kapatıldı.', ['rule' => $rule]) : __('Kural :rule :path altında kapatıldı.', ['rule' => $rule, 'path' => $path]), source: $this->getId(),
            undo: ['method' => 'removeExclusion', 'args' => [$exclusion->id]]);
    }

    public function removeExclusion(int $id, Waf $waf): void
    {
        $exclusion = WafExclusion::where('site_id', $this->siteId)->find($id);
        if ($exclusion === null) {
            return;
        }
        try {
            $waf->removeExclusion($exclusion);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: Waf::message($e));

            return;
        }
        $this->dispatch('toast', message: __('Kural :rule_id yeniden açıldı.', ['rule_id' => $exclusion->rule_id]), source: $this->getId(),
            undo: ['method' => 'restoreExclusion', 'args' => [$exclusion->id]]);
    }

    public function restoreExclusion(int $id, Waf $waf): void
    {
        $exclusion = WafExclusion::onlyTrashed()->where('site_id', $this->siteId)->find($id);
        if ($exclusion === null) {
            return;
        }
        try {
            $waf->restoreExclusion($exclusion);
        } catch (AgentException $e) {
            $this->dispatch('toast', message: Waf::message($e));
        }
    }

    public function render(Waf $waf): View
    {
        $site = $this->site();
        $installed = $waf->installed();
        $server = $installed ? $waf->server() : ['mode' => 'detect', 'paranoia' => 1];
        $events = [];
        $error = null;
        if ($installed) {
            try {
                $events = $waf->events(collect([$site]), limit: 50)['events'];
            } catch (AgentException $e) {
                $error = Waf::message($e);
            }
        }

        return view('livewire.sites.waf', [
            'site' => $site,
            'installed' => $installed,
            'server' => $server,
            'effective' => $site->waf_mode ?? $server['mode'],
            'exclusions' => WafExclusion::with('user')->where('site_id', $site->id)->orderBy('rule_id')->get(),
            'events' => $events,
            'wafError' => $error,
            'admin' => auth()->user()->is_admin,
            'blocked' => auth()->user()->is_admin ? IpBlock::active()->pluck('address')->all() : [],
        ])->title('WAF · '.$site->domain.' · Serfex');
    }
}
