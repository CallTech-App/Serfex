<?php

namespace App\Livewire\Security;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Site;
use App\Services\Agent\AgentException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Security\AddressGeo;
use App\Services\Security\AddressVisits;
use App\Services\Security\IpAllows;
use App\Support\Badge;
use App\Support\Load;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Güvenlik > IP engelleri > one address or range: the sites it went to
 * and its newest requests (from the sites' access logs, read by the
 * agent after the page is drawn), its blocks, and the same actions as
 * the list: block, unblock, another duration, allow.
 */
#[Layout('components.layouts.app')]
class IpAddressShow extends Component
{
    use UndoesBlocks;

    public const TABS = ['sites' => 'Girdiği siteler', 'requests' => 'Son istekler', 'blocks' => 'Engel geçmişi'];

    /** Replies the newest requests are narrowed to: a class, or refused requests. */
    public const REPLIES = ['2' => '2xx', '3' => '3xx', '4' => '4xx', '5' => '5xx', '403' => '403'];

    public string $address = '';

    #[Url(except: 'sites')]
    public string $tab = 'sites';

    /** False until the page is drawn; reading the logs can take seconds. */
    public bool $ready = false;

    // Son istekler
    public string $site = '';

    public string $status = '';

    public string $path = '';

    // Engelle
    public bool $blocking = false;

    public int $minutes = 1440;

    public string $reason = '';

    public function mount(string $address): void
    {
        abort_if(IpBlocks::flaw($address) !== null, 404);
        $this->address = IpBlocks::normalize($address);
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'sites';
        }
    }

    public function updatedTab(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'sites';
        }
    }

    /**
     * Called once the page is drawn (wire:init).
     */
    public function load(): void
    {
        $this->ready = true;
    }

    /**
     * "Yenile" and "Yeniden dene": reads the logs again.
     */
    public function reload(AddressVisits $visits): void
    {
        $this->ready = true;
        try {
            $visits->for($this->address, fresh: true);
        } catch (AgentException) {
            // render() says what went wrong.
        }
    }

    /**
     * "İstekleri gör" on a site's row.
     */
    public function showRequests(string $domain): void
    {
        $this->tab = 'requests';
        $this->site = mb_substr($domain, 0, 253);
        $this->reset('status', 'path');
    }

    public function openBlock(): void
    {
        $this->reset('reason');
        $this->minutes = 1440;
        $this->resetValidation();
        $this->blocking = true;
    }

    public function saveBlock(IpBlocks $blocks): void
    {
        $this->reason = trim($this->reason);
        $this->validate([
            'minutes' => ['required', 'integer', Rule::in(array_keys(IpBlocks::DURATIONS))],
            'reason' => ['nullable', 'string', 'max:200'],
        ], ['reason.max' => __('Neden en fazla 200 karakter olabilir.')]);
        try {
            $result = $blocks->blockMany([$this->address], $this->minutes, $this->reason, auth()->user(), 'panel', $this->mine());
        } catch (MonitorException|AgentException $e) {
            $this->addError('minutes', $e instanceof MonitorException ? $e->getMessage() : __('Adres engellenemedi: :reason', ['reason' => self::why($e)]));

            return;
        }
        if ($result['blocks']->isEmpty()) {
            $this->addError('minutes', __(':address engellenemedi: :reason.', ['address' => $this->address, 'reason' => $result['skipped'][0]['message'] ?? '']));

            return;
        }
        $this->blocking = false;
        $this->done($this->minutes > 0
            ? __(':address :duration engellendi.', ['address' => $this->address, 'duration' => IpBlocks::DURATIONS[$this->minutes]])
            : __(':address kalıcı olarak engellendi.', ['address' => $this->address]),
            ['forget' => $result['created'], 'restore' => $result['previous'], 'reblock' => $result['replaced']]);
    }

    /**
     * Lifts the blocks on this address: the whole-server block and
     * Fail2ban's bans.
     */
    public function unblock(IpBlocks $blocks): void
    {
        $active = IpBlock::active()->where('address', $this->address)->get();
        if ($active->isEmpty()) {
            return;
        }
        try {
            $ids = $blocks->unblockMany($active, auth()->user());
        } catch (AgentException $e) {
            $this->refused = __('Engel kaldırılamadı: :reason', ['reason' => self::why($e)]);

            return;
        }
        if ($ids !== []) {
            $this->done(__(':address adresinin engeli kaldırıldı.', ['address' => $this->address]), ['reblock' => $ids]);
        }
    }

    /**
     * Gives the block another duration, counted from now.
     */
    public function change(int $minutes, IpBlocks $blocks): void
    {
        $active = IpBlock::active()->where('address', $this->address)->get();
        if (! array_key_exists($minutes, IpBlocks::DURATIONS) || $active->isEmpty()) {
            return;
        }
        try {
            $result = $blocks->changeDuration($active, $minutes, auth()->user());
        } catch (MonitorException|AgentException $e) {
            $this->refused = __('Süre değiştirilemedi: :reason', ['reason' => self::why($e)]);

            return;
        }
        $count = $result['blocks']->count();
        if ($count > 0) {
            $this->done(trans_choice(':count engelin süresi değiştirildi: :duration.', $count, ['count' => $count, 'duration' => IpBlocks::DURATIONS[$minutes]]),
                ['forget' => $result['created'], 'restore' => $result['previous'], 'reblock' => $result['replaced']]);
        }
        $this->refused = self::leftOut($result['skipped']);
    }

    public function allow(IpAllows $allows): void
    {
        try {
            $result = $allows->add($this->address, '', auth()->user());
        } catch (MonitorException|AgentException $e) {
            $this->refused = $e instanceof MonitorException ? $e->getMessage() : __('Adres eklenemedi: :reason', ['reason' => self::why($e)]);

            return;
        }
        $this->done($result['lifted'] !== []
            ? __(':address izinlilere eklendi, engeli kaldırıldı.', ['address' => $this->address])
            : __(':address izinlilere eklendi.', ['address' => $this->address]),
            ['unallow' => [$result['allow']->id], 'reblock' => $result['lifted']]);
    }

    /**
     * Takes this address off the allowed list; an entry that allows a
     * wider range stays.
     */
    public function unallow(IpAllows $allows): void
    {
        $rows = IpAllow::where('address', $this->address)->get();
        if ($rows->isEmpty()) {
            return;
        }
        try {
            $entries = $allows->remove($rows);
        } catch (AgentException $e) {
            $this->refused = __('Adres izinlilerden kaldırılamadı: :reason', ['reason' => self::why($e)]);

            return;
        }
        $this->done(__(':address izinlilerden kaldırıldı.', ['address' => $this->address]), ['allow' => $entries]);
    }

    public function render(AddressVisits $visits, IpAllows $allows, AddressGeo $geo): View
    {
        $history = IpBlock::where('address', $this->address)->with('user', 'remover', 'site')->orderByDesc('id')->limit(100)->get();
        $active = $history->filter(fn (IpBlock $b) => $b->isActive())->values();
        $block = $active->firstWhere('source', '!=', 'fail2ban');
        $ban = $active->firstWhere('source', 'fail2ban');
        $allow = $allows->covering($this->address);
        // A wider block that holds this address.
        $range = $block === null
            ? IpBlock::active()->where('source', '!=', 'fail2ban')->where('address', 'like', '%/%')->where('address', '!=', $this->address)->get()
                ->first(fn (IpBlock $b) => IpBlocks::covers($b->address, $this->address))
            : null;

        $report = null;
        $failed = null;
        if ($this->ready) {
            try {
                $report = $visits->for($this->address);
            } catch (AgentException $e) {
                $failed = self::why($e);
            }
        }
        $recent = collect($report['recent'] ?? [])->map(fn ($r) => [
            'at' => self::time($r['at'] ?? null), 'domain' => (string) ($r['domain'] ?? ''), 'method' => mb_substr((string) ($r['method'] ?? ''), 0, 12),
            'path' => (string) ($r['path'] ?? ''), 'status' => (int) ($r['status'] ?? 0), 'agent' => mb_substr((string) ($r['agent'] ?? ''), 0, 300),
        ]);
        $sites = collect($report['sites'] ?? [])->map(fn ($s) => [
            'domain' => (string) ($s['domain'] ?? ''), 'requests' => (int) ($s['requests'] ?? 0), 'denied' => (int) ($s['denied'] ?? 0),
            'top_path' => (string) ($s['top_path'] ?? ''), 'top_count' => (int) ($s['top_count'] ?? 0),
            'statuses' => collect((array) ($s['statuses'] ?? []))->map(fn ($n) => (int) $n)->sortKeys()->all(), 'last' => self::time($s['last'] ?? null),
        ])->sortByDesc('requests')->values();
        $needle = mb_strtolower(trim($this->path));

        return view('livewire.security.ip-address', [
            'history' => $history,
            'block' => $block,
            'ban' => $ban,
            'allow' => $allow,
            'range' => $range,
            'badge' => $this->badge($block, $ban, $allow, $range),
            'country' => $this->country($history, $allow, $geo),
            'isMine' => in_array($this->address, $this->mine(), true),
            'report' => $report,
            'failed' => $failed,
            'sites' => $sites,
            'known' => Site::whereIn('domain', $sites->pluck('domain')->all())->get()->keyBy('domain'),
            'recent' => $recent,
            'shown' => $recent->filter(fn (array $r) => ($this->site === '' || $r['domain'] === $this->site)
                && ($this->status === '' || str_starts_with((string) $r['status'], $this->status))
                && ($needle === '' || str_contains(mb_strtolower($r['path']), $needle)))->values(),
            'first' => self::time($report['first'] ?? null),
            'last' => self::time($report['last'] ?? null),
            'bans' => IpBlock::where('address', $this->address)->where('source', 'fail2ban')->count(),
        ])->title($this->address.' · Serfex');
    }

    /**
     * Where the address stands, next to its name.
     */
    private function badge(?IpBlock $block, ?IpBlock $ban, ?IpAllow $allow, ?IpBlock $range): Badge
    {
        return match (true) {
            $block !== null => new Badge($block->expires_at === null ? __('Engelli · kalıcı') : __('Engelli · :left', ['left' => mb_strtolower(Load::remaining($block->expires_at))]), 'info'),
            $range !== null => new Badge(__('Engelli'), 'info'),
            $ban !== null => new Badge(__('Fail2ban engeli · :left', ['left' => mb_strtolower(Load::remaining($ban->expires_at))]), 'info'),
            $allow !== null => new Badge(__('İzinli'), 'ok'),
            default => new Badge(__('Engelli değil'), 'muted'),
        };
    }

    /**
     * The address's country: from one of its rows, or asked once.
     *
     * @param  iterable<IpBlock>  $history
     */
    private function country(iterable $history, ?IpAllow $allow, AddressGeo $geo): ?string
    {
        foreach ($history as $row) {
            if ($row->country !== null) {
                return $row->country;
            }
        }
        if ($allow !== null && $allow->address === $this->address && $allow->country !== null) {
            return $allow->country;
        }

        return $geo->lookup([$this->address])[$this->address] ?? null;
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
