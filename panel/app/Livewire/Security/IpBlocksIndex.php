<?php

namespace App\Livewire\Security;

use App\Models\CountryBlock;
use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Services\Agent\AgentException;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Services\Security\AddressGeo;
use App\Services\Security\CountryBlocks;
use App\Services\Security\IpAllows;
use App\Support\Countries;
use App\Support\Num;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Güvenlik > IP engelleri: every address the server does not answer, in
 * one list (blocked by hand, by Fail2ban, by Serfex AI or through the
 * API), the addresses that are never blocked, the countries that are
 * refused, and the blocks that are over. Addresses are blocked from a
 * pasted list and lifted, allowed or given another duration many at a
 * time; every action can be undone.
 */
#[Layout('components.layouts.app')]
#[Title('IP engelleri · Serfex')]
class IpBlocksIndex extends Component
{
    use ManagesCountries, UndoesBlocks, WithPagination;

    public const TABS = ['blocked' => 'Engelli adresler', 'allowed' => 'İzinli adresler', 'countries' => 'Ülkeler', 'history' => 'Geçmiş'];

    public const SOURCES = ['panel' => 'Elle', 'fail2ban' => 'Fail2ban', 'ai' => 'Serfex AI', 'api' => 'API'];

    /** How far back Geçmiş looks, in days. */
    public const PERIODS = [1 => 'Son 24 saat', 7 => 'Son 7 gün', 30 => 'Son 30 gün', 90 => 'Son 90 gün'];

    public const PER_PAGE = 25;

    /** The longest text the paste dialog takes, in characters: room for 1,000 addresses with notes. */
    public const PASTE_MAX = 200_000;

    #[Url(except: 'blocked')]
    public string $tab = 'blocked';

    #[Url(except: '')]
    public string $source = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    /** new: last blocked first; ending: the block that ends soonest first. */
    #[Url(except: 'new')]
    public string $sort = 'new';

    #[Url(except: 30)]
    public int $days = 30;

    /** @var list<int|string> ids of the rows ticked on this tab; on Ülkeler, country codes */
    public array $selected = [];

    /** Every address of the filtered list is selected, not only the ticked ones. */
    public bool $allMatching = false;

    // IP engelle
    public bool $pasting = false;

    public string $paste = '';

    public int $minutes = 1440;

    public string $reason = '';

    // İzinli adres ekle
    public bool $allowing = false;

    public string $allowAddress = '';

    public string $allowNote = '';

    public function mount(): void
    {
        $this->tidy();
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['tab', 'source', 'search', 'sort', 'days'], true)) {
            $this->tidy();
            $this->clearSelection();
            $this->resetPage();
        }
    }

    public function updatedSelected(): void
    {
        $this->allMatching = false;
    }

    /**
     * The box in the table's head: ticks or clears the rows of this page.
     */
    public function selectPage(bool $on): void
    {
        $this->allMatching = false;
        $this->selected = $on ? $this->pageIds() : [];
    }

    /**
     * "Listedeki N adresin tümünü seç": the whole filtered list, also the
     * pages that are not shown.
     */
    public function selectAllMatching(): void
    {
        if ($this->tab === 'blocked' && $this->selected !== []) {
            $this->allMatching = true;
        }
    }

    public function clearSelection(): void
    {
        $this->selected = [];
        $this->allMatching = false;
    }

    // --- Engelli adresler ----------------------------------------------

    public function openPaste(?string $address = null): void
    {
        $this->reset('paste', 'reason');
        $this->minutes = 1440;
        $this->paste = $address !== null ? mb_substr(trim($address), 0, 64) : '';
        $this->resetValidation();
        $this->pasting = true;
    }

    public function saveBlocks(IpBlocks $blocks): void
    {
        $this->reason = trim($this->reason);
        $this->validate([
            'paste' => ['required', 'string', 'max:'.self::PASTE_MAX],
            'minutes' => ['required', 'integer', Rule::in(array_keys(IpBlocks::DURATIONS))],
            'reason' => ['nullable', 'string', 'max:200'],
        ], [
            'paste.required' => __('Engellenecek adresleri yazın ya da yapıştırın.'),
            'paste.max' => self::tooMany(),
            'reason.max' => __('Neden en fazla 200 karakter olabilir.'),
        ]);

        $check = $blocks->inspect($this->paste, $this->mine());
        $addresses = [...$check['new'], ...$check['update']->pluck('address')->unique()->values()->all()];
        if ($check['over']) {
            $this->addError('paste', self::tooMany());

            return;
        }
        if ($addresses === []) {
            $this->addError('paste', __('Listede engellenecek adres yok.'));

            return;
        }
        try {
            $result = $blocks->blockMany($addresses, $this->minutes, $this->reason, auth()->user(), 'panel', $this->mine());
        } catch (MonitorException|AgentException $e) {
            // Nothing was blocked and nothing was stored.
            $this->addError('paste', $e instanceof MonitorException ? $e->getMessage() : __('Adresler engellenemedi: :reason', ['reason' => self::why($e)]));

            return;
        }
        $created = count($result['created']);
        $changed = count($result['previous']);
        if ($created + $changed === 0) {
            $this->addError('paste', self::leftOut($result['skipped']) ?? __('Listede engellenecek adres yok.'));

            return;
        }
        $this->pasting = false;
        $this->reset('paste', 'reason');
        $this->clearSelection();
        $this->resetPage();
        $this->done(match (true) {
            $created === 1 && $changed === 0 => __(':address engellendi.', ['address' => $result['blocks']->first()->address]),
            $changed === 0 => trans_choice(':count adres engellendi.', $created, ['count' => $created]),
            $created === 0 => trans_choice(':count adresin süresi değiştirildi.', $changed, ['count' => $changed]),
            default => __(':created adres engellendi, :changed adresin süresi değiştirildi.', ['created' => $created, 'changed' => $changed]),
        }, ['forget' => $result['created'], 'restore' => $result['previous'], 'reblock' => $result['replaced']]);
        $this->refused = self::leftOut($result['skipped']);
    }

    public function unblock(int $id, IpBlocks $blocks): void
    {
        $block = IpBlock::find($id);
        if ($block !== null && $block->isActive()) {
            $this->lift(new Collection([$block]), $blocks);
        }
    }

    public function unblockSelected(IpBlocks $blocks): void
    {
        $this->lift($this->chosenBlocks(), $blocks);
        $this->clearSelection();
    }

    /**
     * Moves the selected addresses to the allowed list; their blocks end.
     */
    public function allowSelected(IpAllows $allows): void
    {
        $entries = $this->chosenBlocks()->pluck('address')->unique()->map(fn (string $a) => ['address' => $a])->values()->all();
        $this->clearSelection();
        if ($entries === []) {
            return;
        }
        try {
            $result = $allows->addMany($entries, auth()->user());
        } catch (MonitorException|AgentException $e) {
            $this->refused = __('Adresler izinlilere eklenemedi: :reason', ['reason' => self::why($e)]);

            return;
        }
        $count = count($result['allows']);
        if ($count > 0) {
            $this->done($count === 1
                ? __(':address izinlilere eklendi.', ['address' => IpAllow::find($result['allows'][0])->address ?? ''])
                : trans_choice(':count adres izinlilere eklendi.', $count, ['count' => $count]),
                ['unallow' => $result['allows'], 'reblock' => $result['lifted']]);
        }
        // Each of these is a sentence of its own.
        $this->refused = $result['skipped'] !== [] ? implode(' ', array_column($result['skipped'], 'message')) : null;
    }

    /**
     * Gives the selected blocks another duration, counted from now.
     */
    public function changeSelected(int $minutes, IpBlocks $blocks): void
    {
        if (! array_key_exists($minutes, IpBlocks::DURATIONS)) {
            return;
        }
        $targets = $this->chosenBlocks();
        $this->clearSelection();
        if ($targets->isEmpty()) {
            return;
        }
        try {
            $result = $blocks->changeDuration($targets, $minutes, auth()->user(), $this->mine());
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

    // --- İzinli adresler -----------------------------------------------

    public function openAllow(): void
    {
        $this->reset('allowAddress', 'allowNote');
        $this->resetValidation();
        $this->allowing = true;
    }

    public function saveAllow(IpAllows $allows): void
    {
        $this->allowAddress = trim($this->allowAddress);
        $this->allowNote = trim($this->allowNote);
        $this->validate([
            'allowAddress' => ['required', 'string', 'max:64'],
            'allowNote' => ['nullable', 'string', 'max:200'],
        ], [
            'allowAddress.required' => __('Bir IP adresi ya da aralık yazın.'),
            'allowAddress.max' => __('Bir IP adresi ya da aralık yazın.'),
            'allowNote.max' => __('Not en fazla 200 karakter olabilir.'),
        ]);
        try {
            $result = $allows->add($this->allowAddress, $this->allowNote, auth()->user());
        } catch (MonitorException|AgentException $e) {
            $this->addError('allowAddress', $e instanceof MonitorException ? $e->getMessage() : __('Adres eklenemedi: :reason', ['reason' => self::why($e)]));

            return;
        }
        $this->allowing = false;
        $this->done($result['lifted'] !== []
            ? __(':address izinlilere eklendi, engeli kaldırıldı.', ['address' => $result['allow']->address])
            : __(':address izinlilere eklendi.', ['address' => $result['allow']->address]),
            ['unallow' => [$result['allow']->id], 'reblock' => $result['lifted']]);
    }

    public function removeAllow(int $id, IpAllows $allows): void
    {
        $this->takeOff(IpAllow::whereKey($id)->get(), $allows);
    }

    public function removeAllows(IpAllows $allows): void
    {
        $rows = IpAllow::whereKey($this->ids())->get();
        $this->clearSelection();
        $this->takeOff($rows, $allows);
    }

    // --- Geçmiş --------------------------------------------------------

    /**
     * "Yeniden engelle": one ended block, or the selected ones, each for
     * as long as it was blocked before.
     */
    public function blockAgain(IpBlocks $blocks, ?int $id = null): void
    {
        $rows = IpBlock::ended()->whereKey($id !== null ? [$id] : $this->ids())->get();
        if ($id === null) {
            $this->clearSelection();
        }
        if ($rows->isEmpty()) {
            return;
        }
        try {
            $result = $blocks->blockAgain($rows, auth()->user(), $this->mine());
        } catch (MonitorException|AgentException $e) {
            $this->refused = __('Adresler engellenemedi: :reason', ['reason' => self::why($e)]);

            return;
        }
        $count = $result['blocks']->count();
        if ($count > 0) {
            $this->done($count === 1
                ? __(':address yeniden engellendi.', ['address' => $result['blocks']->first()->address])
                : trans_choice(':count adres yeniden engellendi.', $count, ['count' => $count]),
                ['forget' => $result['created'], 'restore' => $result['previous'], 'reblock' => $result['replaced']]);
        }
        $this->refused = self::leftOut($result['skipped']);
    }

    public function render(IpBlocks $blocks, IpAllows $allows, AddressGeo $geo, CountryBlocks $countries): View
    {
        $own = $allows->own();
        $data = [
            'counts' => ['blocked' => IpBlock::active()->count(), 'allowed' => IpAllow::count() + count($own), 'countries' => CountryBlock::count()],
            'mine' => $this->mine()[0] ?? '',
            'server' => $own,
            'own' => [],
            'matching' => 0,
            'check' => null,
            'tooLong' => false,
        ];
        if ($this->tab === 'countries') {
            // Countries are not rows of a table: nothing to look up for them.
            return view('livewire.security.ip-blocks', $this->countryData($countries) + $data + ['tooMany' => self::tooMany()]);
        }
        if ($this->tab === 'allowed') {
            $rows = $this->allowedQuery()->with('user')->orderByDesc('id')->paginate(self::PER_PAGE);
            // The server's own addresses close the list.
            if (! $rows->hasMorePages()) {
                $term = mb_strtolower(trim($this->search));
                $data['own'] = array_values(array_filter($own, fn (string $a) => $term === '' || str_contains($a, $term)));
                $data['ownCountries'] = $geo->lookup($data['own']);
            }
        } elseif ($this->tab === 'history') {
            $rows = $this->historyQuery()->with('user', 'remover')->orderByRaw('coalesce(removed_at, expires_at) desc')->orderByDesc('id')->paginate(self::PER_PAGE);
            $addresses = $rows->getCollection()->pluck('address')->unique()->all();
            $data['blockedNow'] = IpBlock::active()->whereIn('address', $addresses)->pluck('address')->flip();
            $known = IpAllow::all();
            $data['allowedNow'] = collect($addresses)->filter(fn (string $a) => IpAllows::match($a, $known) !== null)->flip();
        } else {
            $query = $this->blockedQuery()->with('site', 'user');
            $rows = ($this->sort === 'ending'
                ? $query->orderByRaw('expires_at is null')->orderBy('expires_at')
                : $query->orderByDesc('created_at'))->orderByDesc('id')->paginate(self::PER_PAGE);
            $data['matching'] = $rows->total();
            $data['lookup'] = $rows->total() === 0 && $this->source === '' && $this->search !== '' && IpBlocks::flaw($this->search) === null
                ? IpBlocks::normalize($this->search)
                : null;
            if ($this->pasting && trim($this->paste) !== '') {
                // A text longer than the dialog takes is not read at every keystroke.
                $data['tooLong'] = mb_strlen($this->paste) > self::PASTE_MAX;
                $data['check'] = $data['tooLong'] ? null : $blocks->inspect($this->paste, $this->mine());
            }
        }
        $geo->fill($rows->getCollection());

        return view('livewire.security.ip-blocks', $data + ['rows' => $rows, 'tooMany' => self::tooMany()]);
    }

    /**
     * @param  Collection<int, IpBlock>  $targets
     */
    private function lift(Collection $targets, IpBlocks $blocks): void
    {
        if ($targets->isEmpty()) {
            return;
        }
        try {
            $ids = $blocks->unblockMany($targets, auth()->user());
        } catch (AgentException $e) {
            $this->refused = __('Engel kaldırılamadı: :reason', ['reason' => self::why($e)]);

            return;
        }
        $addresses = $targets->whereIn('id', $ids)->pluck('address')->unique()->values();
        if ($addresses->isEmpty()) {
            return;
        }
        $this->done($addresses->count() === 1
            ? __(':address adresinin engeli kaldırıldı.', ['address' => $addresses[0]])
            : trans_choice(':count adresin engeli kaldırıldı.', $addresses->count(), ['count' => $addresses->count()]),
            ['reblock' => $ids]);
        if (count($ids) < $targets->count()) {
            $this->refused = __('Bazı Fail2ban engelleri kaldırılamadı; listede duruyorlar.');
        }
    }

    /**
     * @param  Collection<int, IpAllow>  $rows
     */
    private function takeOff(Collection $rows, IpAllows $allows): void
    {
        if ($rows->isEmpty()) {
            return;
        }
        try {
            $entries = $allows->remove($rows);
        } catch (MonitorException|AgentException $e) {
            $this->refused = __('Adres izinlilerden kaldırılamadı: :reason', ['reason' => self::why($e)]);

            return;
        }
        $this->done(count($entries) === 1
            ? __(':address izinlilerden kaldırıldı.', ['address' => $entries[0]['address']])
            : trans_choice(':count adres izinlilerden kaldırıldı.', count($entries), ['count' => count($entries)]),
            ['allow' => $entries]);
    }

    /**
     * The blocks in force the bulk buttons act on: the ticked rows, or
     * the whole filtered list. A block that ended since the page was
     * drawn is not among them.
     *
     * @return Collection<int, IpBlock>
     */
    private function chosenBlocks(): Collection
    {
        if ($this->tab !== 'blocked') {
            return new Collection;
        }

        return ($this->allMatching ? $this->blockedQuery() : IpBlock::active()->whereKey($this->ids()))->orderBy('id')->get();
    }

    /**
     * @return Builder<IpBlock>
     */
    private function blockedQuery(): Builder
    {
        return $this->filtered(IpBlock::active());
    }

    /**
     * @return Builder<IpBlock>
     */
    private function historyQuery(): Builder
    {
        $since = now()->subDays($this->days);

        return $this->filtered(IpBlock::ended()->where(fn ($q) => $q->where('removed_at', '>=', $since)
            ->orWhere(fn ($q) => $q->whereNull('removed_at')->where('expires_at', '>=', $since))));
    }

    /**
     * The source filter and the search box: part of an address, of the
     * reason or of the site's name, a country by its code or name, or an
     * address that a blocked range holds.
     *
     * @param  Builder<IpBlock>  $query
     * @return Builder<IpBlock>
     */
    private function filtered(Builder $query): Builder
    {
        $query->when($this->source !== '', fn ($q) => $q->where('source', $this->source));
        $term = mb_strtolower(trim($this->search));
        if ($term === '') {
            return $query;
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $countries = $this->countries(IpBlock::query(), $term);
        $inside = [];
        if (IpBlocks::flaw($term) === null && ! str_contains($term, '/')) {
            $inside = IpBlock::query()->where('address', 'like', '%/%')->toBase()->distinct()->pluck('address')
                ->filter(fn (string $range) => IpBlocks::contains($range, $term))->values()->all();
        }

        return $query->where(fn ($q) => $q->where('address', 'like', $like)
            ->orWhereRaw('lower(reason) like ?', [$like])
            ->orWhereHas('site', fn ($s) => $s->where('domain', 'like', $like))
            ->when($countries !== [], fn ($q) => $q->orWhereIn('country', $countries))
            ->when($inside !== [], fn ($q) => $q->orWhereIn('address', $inside)));
    }

    /**
     * @return Builder<IpAllow>
     */
    private function allowedQuery(): Builder
    {
        $term = mb_strtolower(trim($this->search));
        if ($term === '') {
            return IpAllow::query();
        }
        $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';
        $countries = $this->countries(IpAllow::query(), $term);

        return IpAllow::query()->where(fn ($q) => $q->where('address', 'like', $like)
            ->orWhereRaw('lower(note) like ?', [$like])
            ->when($countries !== [], fn ($q) => $q->orWhereIn('country', $countries)));
    }

    /**
     * The country codes in a table whose code is $term or whose name
     * holds it.
     *
     * @param  Builder<IpBlock>|Builder<IpAllow>  $query
     * @return list<string>
     */
    private function countries(Builder $query, string $term): array
    {
        return $query->whereNotNull('country')->toBase()->distinct()->pluck('country')
            ->filter(fn (string $code) => mb_strtolower($code) === $term || str_contains(Countries::fold(AddressGeo::name($code)), Countries::fold($term)))
            ->values()->all();
    }

    /**
     * @return list<int>
     */
    private function ids(): array
    {
        return array_values(array_unique(array_map('intval', $this->selected)));
    }

    /**
     * The ids of the rows on the page that is shown; on Ülkeler, the
     * codes of its countries.
     *
     * @return list<int|string>
     */
    private function pageIds(): array
    {
        if ($this->tab === 'countries') {
            return $this->countryPageCodes();
        }
        $query = match ($this->tab) {
            'allowed' => $this->allowedQuery()->orderByDesc('id'),
            'history' => $this->historyQuery()->orderByRaw('coalesce(removed_at, expires_at) desc')->orderByDesc('id'),
            default => ($this->sort === 'ending'
                ? $this->blockedQuery()->orderByRaw('expires_at is null')->orderBy('expires_at')
                : $this->blockedQuery()->orderByDesc('created_at'))->orderByDesc('id'),
        };

        return $query->forPage($this->getPage(), self::PER_PAGE)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function tidy(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'blocked';
        }
        if ($this->source !== '' && ! isset(self::SOURCES[$this->source])) {
            $this->source = '';
        }
        if (! in_array($this->sort, ['new', 'ending'], true)) {
            $this->sort = 'new';
        }
        if (! isset(self::PERIODS[$this->days])) {
            $this->days = 30;
        }
        $this->search = mb_substr($this->search, 0, 100);
    }

    private static function tooMany(): string
    {
        return __('Bir kerede en fazla :n adres engellenebilir; listeyi bölün.', ['n' => Num::format(IpBlocks::MAX_BATCH)]);
    }
}
