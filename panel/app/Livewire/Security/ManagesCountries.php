<?php

namespace App\Livewire\Security;

use App\Models\CountryBlock;
use App\Models\IpAllow;
use App\Services\Agent\AgentException;
use App\Services\Monitor\MonitorException;
use App\Services\Security\CountryBlocks;
use App\Support\Countries;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * The Ülkeler tab of IP engelleri: every country with what it sent the
 * sites in the last thirty days, the dialog that blocks countries and
 * sets the scope, and lifting a block. The country the reader is
 * connected from cannot be blocked.
 */
trait ManagesCountries
{
    // Ülke engelle
    public bool $blockingCountries = false;

    /** @var list<string> the countries chosen in the dialog */
    public array $countryCodes = [];

    public string $countryScope = 'web';

    /** What is typed into "Ülke ekle…". */
    public string $countryQuery = '';

    /** The dialog only changes the scope ("Kapsamı değiştir"). */
    public bool $scopeOnly = false;

    /**
     * "Ülke engelle": the dialog with one country, or with the ticked
     * ones that are not blocked yet.
     */
    public function openCountries(?string $code = null): void
    {
        $countries = app(CountryBlocks::class);
        if (! $this->countriesReady($countries)) {
            return;
        }
        $this->reset('countryCodes', 'countryQuery');
        foreach ($code !== null ? [$code] : $this->selected as $wanted) {
            $this->addCountry((string) $wanted);
        }
        if ($code !== null && $this->countryCodes === []) {
            // Blocked already, or where the reader is connected from.
            return;
        }
        $this->countryScope = $countries->scope();
        $this->scopeOnly = false;
        $this->resetValidation();
        $this->blockingCountries = true;
    }

    /**
     * "Kapsamı değiştir": the same dialog without the countries.
     */
    public function openScope(): void
    {
        $countries = app(CountryBlocks::class);
        if (! $this->countriesReady($countries)) {
            return;
        }
        $this->reset('countryCodes', 'countryQuery');
        $this->countryScope = $countries->scope();
        $this->scopeOnly = true;
        $this->resetValidation();
        $this->blockingCountries = true;
    }

    public function addCountry(string $code): void
    {
        $countries = app(CountryBlocks::class);
        $code = strtoupper(trim($code));
        $this->countryQuery = '';
        if (! Countries::valid($code) || in_array($code, $this->countryCodes, true)
            || in_array($code, $countries->codes(), true) || $code === $countries->home($this->mine())) {
            return;
        }
        $this->countryCodes[] = $code;
        $this->resetValidation('countryCodes');
    }

    public function dropCountry(string $code): void
    {
        $this->countryCodes = array_values(array_diff($this->countryCodes, [strtoupper(trim($code))]));
    }

    public function saveCountries(CountryBlocks $countries): void
    {
        if ($this->scopeOnly) {
            $this->saveScope($countries);

            return;
        }
        $codes = array_values(array_filter(array_map(fn ($c) => strtoupper(trim((string) $c)), $this->countryCodes), Countries::valid(...)));
        if ($codes === []) {
            $this->addError('countryCodes', __('Engellenecek ülkeyi seçin.'));

            return;
        }
        try {
            $result = $countries->block($codes, $this->countryScope, auth()->user(), $this->mine());
        } catch (MonitorException|AgentException $e) {
            // Nothing was blocked and nothing was stored.
            $this->addError('countryCodes', $e instanceof MonitorException ? $e->getMessage() : __('Ülkeler engellenemedi: :reason', ['reason' => self::why($e)]));

            return;
        }
        $blocked = $result['blocked'];
        $skipped = implode(' ', array_map(fn (array $s) => $s['text'].': '.$s['message'], $result['skipped']));
        if ($blocked === []) {
            $this->addError('countryCodes', $skipped !== '' ? $skipped : __('Engellenecek ülkeyi seçin.'));

            return;
        }
        $this->blockingCountries = false;
        $this->reset('countryCodes', 'countryQuery');
        $this->clearSelection();
        $this->done(count($blocked) === 1
            ? __(':country engellendi.', ['country' => Countries::name($blocked[0])])
            : trans_choice(':count ülke engellendi.', count($blocked), ['count' => count($blocked)]),
            ['uncountry' => $blocked, 'scope' => $result['scope'] !== $countries->scope() ? $result['scope'] : null]);
        $this->refused = $skipped !== '' ? $skipped : null;
    }

    public function unblockCountry(string $code, CountryBlocks $countries): void
    {
        $this->liftCountries([$code], $countries);
    }

    public function unblockCountries(CountryBlocks $countries): void
    {
        $codes = array_map('strval', $this->selected);
        $this->clearSelection();
        $this->liftCountries($codes, $countries);
    }

    /**
     * What the tab and its dialog show.
     *
     * @return array<string, mixed>
     */
    protected function countryData(CountryBlocks $countries): array
    {
        $status = $countries->status();
        $home = $countries->home($this->mine());
        $blocked = CountryBlock::with('user')->get()->keyBy('code');
        $traffic = $countries->traffic();
        $data = [
            'rows' => $this->countryRows($countries, $blocked->all(), $traffic, $status['ranges'], $home),
            'status' => $status,
            'scope' => $countries->scope(),
            'home' => $home,
            'blockedCodes' => $blocked->keys()->all(),
            'chosen' => [],
            'suggestions' => [],
            'chosenTraffic' => ['requests' => 0, 'visitors' => 0],
            'allowedCount' => 0,
        ];
        if (! $this->blockingCountries) {
            return $data;
        }
        $data['chosen'] = array_values(array_filter($this->countryCodes, Countries::valid(...)));
        foreach ($data['chosen'] as $code) {
            $data['chosenTraffic']['requests'] += $traffic[$code]['requests'] ?? 0;
            $data['chosenTraffic']['visitors'] += $traffic[$code]['visitors'] ?? 0;
        }
        $taken = [...$data['chosen'], ...$blocked->keys()->all(), ...($home !== null ? [$home] : [])];
        $data['suggestions'] = array_slice(array_values(array_diff(Countries::find($this->countryQuery), $taken)), 0, 6);
        $data['allowedCount'] = IpAllow::count();

        return $data;
    }

    /**
     * The codes of the countries on the page that is shown, without the
     * one the reader is connected from.
     *
     * @return list<string>
     */
    protected function countryPageCodes(): array
    {
        $countries = app(CountryBlocks::class);
        $rows = $this->countryRows($countries, CountryBlock::all()->keyBy('code')->all(), $countries->traffic(), [], $countries->home($this->mine()));

        return array_values(array_map(fn (object $r) => $r->code, array_filter($rows->items(), fn (object $r) => ! $r->home)));
    }

    public static function scopeName(string $scope): string
    {
        return $scope === 'server' ? __('bütün sunucu') : __('yalnızca web siteleri');
    }

    /**
     * Every country, or the ones the search finds: the blocked ones
     * first (the newest block on top), then by the requests of the last
     * thirty days, then by name.
     *
     * @param  array<string, CountryBlock>  $blocked
     * @param  array<string, array{requests: int, visitors: int}>  $traffic
     * @param  array<string, int>  $ranges
     * @return LengthAwarePaginator<int, object>
     */
    private function countryRows(CountryBlocks $countries, array $blocked, array $traffic, array $ranges, ?string $home): LengthAwarePaginator
    {
        $term = trim($this->search);
        $names = [];
        foreach ($term === '' ? Countries::CODES : Countries::find($term) as $code) {
            $names[$code] = Countries::name($code);
        }
        $addresses = $countries->blockedAddresses();
        $rows = [];
        $place = 0;
        foreach (Countries::sorted($names) as $code => $name) {
            $rows[] = (object) [
                'id' => $code, 'code' => $code, 'name' => $name, 'place' => $place++,
                'requests' => $traffic[$code]['requests'] ?? 0,
                'visitors' => $traffic[$code]['visitors'] ?? 0,
                'addresses' => $addresses[$code] ?? 0,
                'block' => $blocked[$code] ?? null,
                'ranges' => $ranges[$code] ?? null,
                'home' => $code === $home,
            ];
        }
        // Countries blocked together stand by their requests, like the rest.
        usort($rows, fn (object $a, object $b) => [$b->block !== null, $b->block?->created_at?->getTimestamp() ?? 0, $b->requests, $a->place]
            <=> [$a->block !== null, $a->block?->created_at?->getTimestamp() ?? 0, $a->requests, $b->place]);
        $page = max(1, (int) $this->getPage());

        return new LengthAwarePaginator(array_slice($rows, ($page - 1) * self::PER_PAGE, self::PER_PAGE), count($rows), self::PER_PAGE, $page);
    }

    private function saveScope(CountryBlocks $countries): void
    {
        try {
            $before = $countries->setScope($this->countryScope);
        } catch (MonitorException|AgentException $e) {
            $this->addError('countryScope', $e instanceof MonitorException ? $e->getMessage() : __('Kapsam değiştirilemedi: :reason', ['reason' => self::why($e)]));

            return;
        }
        $this->blockingCountries = false;
        if ($before !== $this->countryScope) {
            $this->done(__('Kapsam değiştirildi: :scope.', ['scope' => self::scopeName($this->countryScope)]), ['scope' => $before]);
        }
    }

    /**
     * @param  list<string>  $codes
     */
    private function liftCountries(array $codes, CountryBlocks $countries): void
    {
        try {
            $lifted = $countries->unblock($codes);
        } catch (MonitorException|AgentException $e) {
            $this->refused = __('Engel kaldırılamadı: :reason', ['reason' => self::why($e)]);

            return;
        }
        if ($lifted === []) {
            return;
        }
        $this->done(count($lifted) === 1
            ? __(':country engeli kaldırıldı.', ['country' => Countries::name($lifted[0]['code'])])
            : trans_choice(':count ülkenin engeli kaldırıldı.', count($lifted), ['count' => count($lifted)]),
            ['country' => $lifted]);
    }

    /**
     * Whether countries can be blocked now; says why when they cannot.
     */
    private function countriesReady(CountryBlocks $countries): bool
    {
        $status = $countries->status();
        if (! $status['reachable']) {
            $this->refused = __('Sunucu ajanına ulaşılamıyor; ülke engelleri şu an değiştirilemez.');
        } elseif (! $status['loaded']) {
            $this->refused = __('Ülke tablosu henüz indirilmedi; birkaç dakika sonra yeniden deneyin.');
        }

        return $status['reachable'] && $status['loaded'];
    }
}
