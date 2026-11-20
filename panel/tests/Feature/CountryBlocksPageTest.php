<?php

namespace Tests\Feature;

use App\Livewire\Security\IpBlocksIndex;
use App\Models\CountryBlock;
use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SiteStatItem;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use App\Services\Security\CountryBlocks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik > IP engelleri > Ülkeler, the tab: every country with what it
 * sent the sites, blocking from the dialog, lifting, the scope, and Undo
 * for each. The reader is connected from Türkiye.
 */
class CountryBlocksPageTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-20 12:00:00');
        $this->admin = User::factory()->create(['name' => 'Sercan Akın']);
        $this->actingAs($this->admin);
        $this->agent = new FakeAgent([
            'firewall.countries.apply' => fn ($p) => self::held($p['countries'], $p['scope']),
            'firewall.countries.list' => fn () => self::held(CountryBlock::orderBy('code')->pluck('code')->all(), (string) Setting::get(CountryBlocks::SCOPE_KEY, 'web')),
            'firewall.allow.apply' => fn ($p) => ['allowed' => $p['addresses'], 'own' => ['203.0.113.10']],
            'firewall.allow.list' => fn () => ['allowed' => IpAllow::pluck('address')->all(), 'own' => ['203.0.113.10']],
            'geo.lookup' => fn ($p) => ['loaded' => true, 'countries' => array_fill_keys($p['addresses'], 'TR')],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    /**
     * @param  list<string>  $countries
     * @return array<string, mixed>
     */
    private static function held(array $countries, string $scope, bool $loaded = true): array
    {
        return [
            'countries' => $countries, 'scope' => $scope, 'ranges' => array_fill_keys($countries, 12),
            'loaded' => $loaded, 'updated' => $loaded ? '2026-11-18T03:10:00Z' : null, 'sites' => 2, 'nginx' => true,
        ];
    }

    private function countries(): CountryBlocks
    {
        return app(CountryBlocks::class);
    }

    private function tab()
    {
        return Livewire::test(IpBlocksIndex::class)->set('tab', 'countries');
    }

    private function undoToken($page): string
    {
        $toast = collect($page->effects['dispatches'] ?? [])->last(fn ($d) => $d['name'] === 'toast' && isset($d['params']['undo']));
        $this->assertNotNull($toast, 'no toast with Undo was shown');

        return $toast['params']['undo']['args'][0];
    }

    /**
     * @param  list<string>  $texts
     */
    private function assertInOrder(array $texts, string $html): void
    {
        $at = 0;
        foreach ($texts as $text) {
            $found = mb_strpos($html, $text, $at);
            $this->assertNotFalse($found, "\"{$text}\" does not come after the texts before it");
            $at = $found + mb_strlen($text);
        }
    }

    /**
     * What the sites got from a country today.
     */
    private function visits(string $code, int $requests, int $visitors): void
    {
        static $site;
        $site = Site::first() ?? Site::factory()->create();
        SiteStatItem::create(['site_id' => $site->id, 'day' => '2026-11-20', 'kind' => 'country', 'value' => $code, 'requests' => $requests, 'visitors' => $visitors]);
    }

    public function test_the_tab_lists_blocked_countries_first_then_by_requests(): void
    {
        $this->visits('US', 96310, 4120);
        $this->visits('DE', 22045, 1930);
        $this->visits('TR', 412880, 38204);
        $this->visits('CN', 18420, 212);
        $this->countries()->block(['CN'], 'web', $this->admin);
        $this->travelTo('2026-11-20 13:00:00');
        $this->countries()->block(['RU'], 'web', $this->admin);
        IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'country' => 'CN']);

        $page = $this->tab();

        // The newest block first, then the busiest countries, then the alphabet.
        $this->assertInOrder(['Rusya', 'Çin', 'Türkiye', 'Amerika Birleşik Devletleri', 'Almanya', 'Afganistan'], $page->html());
        $page->assertSee('2 ülke engelli')
            ->assertSee('yalnızca web siteleri')
            ->assertSee('Kapsamı değiştir')
            ->assertSee('96.310')
            ->assertSee('4.120')
            ->assertSee('Şu an buradan bağlısınız')
            ->assertSee('· Sercan Akın')
            ->assertSee('son: 18 Kas)')
            ->assertSee('1–25 / 249')
            ->assertSeeHtml('wire:click="unblockCountry(\'RU\')"')
            ->assertSeeHtml('wire:click="openCountries(\'US\')"')
            ->assertDontSeeHtml('wire:click="openCountries(\'TR\')"');
        $this->assertMatchesRegularExpression('/Ülkeler\s*(<!--.*?-->\s*)*<span[^>]*>2</u', $page->html());

        $page->call('nextPage')->assertSee('26–50 / 249')->assertDontSee('Rusya');
    }

    public function test_the_tab_count_is_shown_on_the_other_tabs_too(): void
    {
        $this->countries()->block(['RU']);

        $this->assertMatchesRegularExpression('/Ülkeler\s*(<!--.*?-->\s*)*<span[^>]*>1</u', Livewire::test(IpBlocksIndex::class)->html());
        $this->get('/security/ip-blocks?tab=countries')->assertOk()->assertSee('Rusya')->assertSee('Sunucunun yanıt vermediği adresler ve ülkeler.');
    }

    public function test_search_finds_a_country_by_name_or_code(): void
    {
        $this->tab()->set('search', 'alman')->assertSee('Almanya')->assertDontSee('Japonya')->assertSee('1–1 / 1');
        $this->tab()->set('search', 'jp')->assertSee('Japonya')->assertDontSee('Almanya');
        // A capital dotted İ typed as a plain i.
        $this->tab()->set('search', 'isveç')->assertSee('İsveç');
        $this->tab()->set('search', 'qqqq')->assertSee('Aramanızla eşleşen ülke yok.');
    }

    public function test_the_blocked_list_finds_a_turkish_country_name_however_its_i_is_typed(): void
    {
        IpBlock::create(['address' => '192.0.2.77', 'source' => 'panel', 'country' => 'SE']);
        IpBlock::create(['address' => '192.0.2.78', 'source' => 'panel', 'country' => 'DE']);

        Livewire::test(IpBlocksIndex::class)->set('search', 'isveç')->assertSee('192.0.2.77')->assertDontSee('192.0.2.78');
    }

    public function test_blocking_from_the_dialog_sends_the_scope_and_can_be_undone(): void
    {
        $page = $this->tab()
            ->call('openCountries', 'RU')
            ->assertSet('blockingCountries', true)
            ->assertSet('countryCodes', ['RU'])
            ->assertSet('countryScope', 'web')
            ->assertSee('1 ülkeyi engelle')
            ->call('addCountry', 'cn')
            ->assertSet('countryCodes', ['RU', 'CN'])
            ->assertSee('2 ülkeyi engelle')
            ->call('addCountry', 'CN')
            ->call('addCountry', 'XX')
            ->assertSet('countryCodes', ['RU', 'CN'])
            ->set('countryScope', 'server')
            ->call('saveCountries')
            ->assertHasNoErrors()
            ->assertSet('blockingCountries', false)
            ->assertDispatched('toast', message: '2 ülke engellendi.');

        $this->assertSame([['countries' => ['CN', 'RU'], 'scope' => 'server']], $this->agent->called('firewall.countries.apply'));
        $this->assertSame($this->admin->id, CountryBlock::where('code', 'RU')->value('user_id'));
        $page->assertSee('bütün sunucu');

        $page->call('undo', $this->undoToken($page))->assertDispatched('toast', message: 'Geri alındı.');

        $this->assertSame([], $this->countries()->codes());
        $this->assertSame('web', $this->countries()->scope());
        $calls = $this->agent->called('firewall.countries.apply');
        $this->assertSame(['countries' => [], 'scope' => 'web'], end($calls));
    }

    public function test_one_country_is_named_in_the_toast_and_a_chip_can_be_taken_out(): void
    {
        $this->tab()
            ->call('openCountries', 'RU')
            ->call('addCountry', 'CN')
            ->call('dropCountry', 'RU')
            ->assertSet('countryCodes', ['CN'])
            ->call('saveCountries')
            ->assertDispatched('toast', message: 'Çin engellendi.');

        $this->assertSame(['CN'], $this->countries()->codes());
    }

    public function test_the_dialog_does_not_save_an_empty_list(): void
    {
        $this->tab()->call('openCountries')
            ->assertSet('countryCodes', [])
            ->call('saveCountries')
            ->assertHasErrors('countryCodes')
            ->assertSet('blockingCountries', true)
            ->assertSee('Engellenecek ülkeyi seçin.');
        $this->assertSame([], $this->agent->called('firewall.countries.apply'));
    }

    public function test_the_country_i_am_connected_from_is_locked(): void
    {
        $this->visits('TR', 412880, 38204);
        $page = $this->tab()->assertSee('Şu an buradan bağlısınız');

        $page->call('openCountries', 'TR')->assertSet('blockingCountries', false);
        $page->call('openCountries')
            ->assertSee('Bağlı olduğunuz ülke (Türkiye) engellenemez.')
            ->call('addCountry', 'TR')
            ->assertSet('countryCodes', []);
        // A list made by hand is checked again when it is saved.
        $page->set('countryCodes', ['TR'])->call('saveCountries')->assertHasErrors('countryCodes')->assertSee('buradan bağlısınız');

        $this->assertSame([], $this->countries()->codes());
        $this->assertSame([], $this->agent->called('firewall.countries.apply'));
    }

    public function test_the_united_states_get_a_warning(): void
    {
        $this->tab()->call('openCountries', 'DE')->assertDontSee('arama motorları sitelerinizi göremez')
            ->call('addCountry', 'US')->assertSee('arama motorları sitelerinizi göremez');
    }

    public function test_the_dialog_says_what_came_from_the_chosen_countries_and_who_always_passes(): void
    {
        $this->visits('US', 96310, 4120);
        $this->visits('VN', 4870, 57);
        IpAllow::create(['address' => '198.51.100.9']);

        $this->tab()->call('openCountries', 'US')->call('addCountry', 'VN')
            ->assertSee('Son 30 günde bu 2 ülkeden 101.180 istek, 4.177 ziyaretçi geldi.')
            ->assertSee('İzinli adresler (1), sunucunun kendi adresleri ve Cloudflare\'in vekil adresleri.')
            ->assertSee('Yalnızca web siteleri (80, 443)')
            ->assertSee('Bütün sunucu')
            // What the whole-server scope leaves open is said.
            ->assertSee('Gelen posta (25) ve DNS (53) açık kalır')
            ->assertSee('Webmail');
    }

    public function test_the_dialog_suggests_countries_as_they_are_typed(): void
    {
        $this->countries()->block(['BY']);

        $this->tab()->call('openCountries', 'RO')
            ->set('countryQuery', 'rus')
            ->assertSeeHtml('wire:click="addCountry(\'RU\')"')
            // Blocked already, chosen already, and where the reader is.
            ->assertDontSeeHtml('wire:click="addCountry(\'BY\')"')
            ->set('countryQuery', 'ro')
            ->assertDontSeeHtml('wire:click="addCountry(\'RO\')"')
            ->set('countryQuery', 'türkiye')
            ->assertDontSeeHtml('wire:click="addCountry(\'TR\')"')
            ->set('countryQuery', 'rusya')
            ->call('addCountry', 'RU')
            ->assertSet('countryQuery', '')
            ->assertSet('countryCodes', ['RO', 'RU']);
    }

    public function test_unblocking_one_and_several_can_be_undone(): void
    {
        $this->travelTo('2026-11-19 09:00:00');
        $this->countries()->block(['RU', 'CN', 'KP'], 'web', $this->admin);
        $this->travelTo('2026-11-20 12:00:00');

        $page = $this->tab()->call('unblockCountry', 'RU')->assertDispatched('toast', message: 'Rusya engeli kaldırıldı.');
        $this->assertSame(['CN', 'KP'], $this->countries()->codes());
        $page->call('undo', $this->undoToken($page))->assertDispatched('toast', message: 'Geri alındı.');
        $row = CountryBlock::where('code', 'RU')->sole();
        $this->assertSame($this->admin->id, $row->user_id);
        $this->assertSame('2026-11-19 09:00', $row->created_at->format('Y-m-d H:i'));

        // Countries that are not blocked are among the ticked ones.
        $page = $this->tab()->set('selected', ['CN', 'KP', 'DE'])->assertSee('Engeli kaldır')
            ->call('unblockCountries')
            ->assertSet('selected', [])
            ->assertDispatched('toast', message: '2 ülkenin engeli kaldırıldı.');
        $this->assertSame(['RU'], $this->countries()->codes());
        $page->call('undo', $this->undoToken($page));
        $this->assertSame(['CN', 'KP', 'RU'], $this->countries()->codes());
    }

    public function test_changing_the_scope_alone(): void
    {
        $this->countries()->block(['RU']);

        $page = $this->tab()->call('openScope')
            ->assertSet('blockingCountries', true)
            ->assertSet('scopeOnly', true)
            ->assertSet('countryScope', 'web')
            ->assertDontSee('ülkeyi engelle')
            ->set('countryScope', 'server')
            ->call('saveCountries')
            ->assertSet('blockingCountries', false)
            ->assertDispatched('toast', message: 'Kapsam değiştirildi: bütün sunucu.');
        $this->assertSame('server', $this->countries()->scope());
        $this->assertSame(['RU'], $this->countries()->codes());

        $page->call('undo', $this->undoToken($page));
        $this->assertSame('web', $this->countries()->scope());

        // The same scope again: nothing happens, nothing to undo.
        $calls = count($this->agent->called('firewall.countries.apply'));
        $this->tab()->call('openScope')->call('saveCountries')->assertSet('blockingCountries', false)->assertNotDispatched('toast');
        $this->assertCount($calls, $this->agent->called('firewall.countries.apply'));
    }

    public function test_the_scope_cannot_cover_the_whole_server_while_my_country_is_blocked(): void
    {
        // Blocked by a colleague elsewhere, for the web sites.
        $this->countries()->block(['TR', 'RU']);

        $page = $this->tab()->call('openScope')
            ->set('countryScope', 'server')
            ->call('saveCountries')
            ->assertHasErrors('countryScope')
            ->assertSet('blockingCountries', true)
            ->assertSee('Bağlı olduğunuz ülke (Türkiye) engelli')
            ->assertNotDispatched('toast');
        $this->assertSame('web', $this->countries()->scope());

        // Blocking one more country with the whole server as scope is the same change.
        $this->tab()->call('openCountries', 'CN')
            ->set('countryScope', 'server')
            ->call('saveCountries')
            ->assertHasErrors('countryScope')
            ->assertSet('blockingCountries', true)
            ->assertSee('Bağlı olduğunuz ülke (Türkiye) engelli');
        $this->assertSame('web', $this->countries()->scope());
        $this->assertSame(['RU', 'TR'], $this->countries()->codes());
    }

    public function test_my_own_blocked_country_says_so_and_can_be_ticked_and_unblocked(): void
    {
        $this->countries()->block(['TR']);

        $page = $this->tab();
        $this->assertInOrder(['Türkiye', 'Engelli', 'Şu an buradan bağlısınız'], $page->html());
        // No row button on a phone: the box must be there to tick.
        $page->assertSeeHtml('value="TR"');

        $page->call('selectPage', true);
        $this->assertContains('TR', $page->get('selected'));
        $page->call('unblockCountries')->assertDispatched('toast', message: 'Türkiye engeli kaldırıldı.');
        $this->assertSame([], $this->countries()->codes());
    }

    public function test_ticked_countries_fill_the_dialog(): void
    {
        $this->countries()->block(['RU']);

        // Blocked already, and where the reader is connected from, are left out.
        $this->tab()->set('selected', ['RU', 'DE', 'FR', 'TR', 'bogus'])
            ->call('openCountries')
            ->assertSet('countryCodes', ['DE', 'FR']);
    }

    public function test_the_box_in_the_head_ticks_the_countries_of_the_page(): void
    {
        $this->visits('US', 10, 1);
        $this->visits('TR', 9, 1);

        $page = $this->tab()->set('search', 'e')->call('selectPage', true);
        $ticked = $page->get('selected');
        $this->assertCount(24, $ticked);
        $this->assertContains('US', $ticked);
        // The country the reader is connected from cannot be ticked.
        $this->assertNotContains('TR', $ticked);
        $page->call('selectPage', false)->assertSet('selected', []);
    }

    public function test_a_read_only_administrator_can_read_the_tab_but_not_block(): void
    {
        $this->countries()->block(['RU']);
        $calls = count($this->agent->called('firewall.countries.apply'));
        $this->actingAs(User::factory()->create(['role' => 'readonly']));

        $this->get('/security/ip-blocks?tab=countries')->assertOk()->assertSee('Rusya')->assertSee('1 ülke engelli');

        $this->tab()->call('unblockCountry', 'RU');
        $this->tab()->set('countryCodes', ['CN'])->call('saveCountries');
        $this->assertSame(['RU'], $this->countries()->codes());
        $this->assertCount($calls, $this->agent->called('firewall.countries.apply'));
    }

    public function test_without_the_country_table_the_tab_says_so(): void
    {
        $this->agent->answers['firewall.countries.list'] = self::held([], 'web', loaded: false);

        $this->tab()->assertSee('Ülke tablosu henüz indirilmedi')
            ->call('openCountries', 'RU')
            ->assertSet('blockingCountries', false);
        $this->assertSame([], $this->agent->called('firewall.countries.apply'));
    }

    public function test_the_tab_says_when_the_agent_is_away(): void
    {
        $this->agent->answers['firewall.countries.list'] = AgentException::unavailable('/run/serfex/agent.sock', 'connection refused');

        $this->tab()->assertSee('Sunucu ajanına ulaşılamıyor')
            ->call('openCountries', 'RU')
            ->assertSet('blockingCountries', false);
    }

    public function test_the_agent_refusing_leaves_the_dialog_open_with_the_reason(): void
    {
        $this->agent->answers['firewall.countries.apply'] = AgentException::rpcError('firewall.countries.apply', AgentException::OPERATION_FAILED, 'nft: Could not process rule');

        $this->tab()->call('openCountries', 'RU')->call('saveCountries')
            ->assertHasErrors('countryCodes')
            ->assertSet('blockingCountries', true)
            ->assertSee('nft: Could not process rule');
        $this->assertSame([], $this->countries()->codes());
    }

    public function test_an_agent_failure_while_lifting_is_shown_and_nothing_changes(): void
    {
        $this->countries()->block(['RU']);
        $this->agent->answers['firewall.countries.apply'] = AgentException::rpcError('firewall.countries.apply', AgentException::OPERATION_FAILED, 'nginx: reload failed');

        $this->tab()->call('unblockCountry', 'RU')->assertSee('Engel kaldırılamadı: nginx: reload failed');
        $this->assertSame(['RU'], $this->countries()->codes());
    }

    public function test_a_blocked_country_with_no_addresses_in_the_table_says_so(): void
    {
        $this->countries()->block(['RU', 'KP']);
        $this->agent->answers['firewall.countries.list'] = fn () => ['ranges' => ['RU' => 12, 'KP' => 0]] + self::held(['KP', 'RU'], 'web');
        Cache::forget(CountryBlocks::STATUS_KEY);

        $html = $this->tab()->html();
        $this->assertSame(1, substr_count($html, 'Tabloda bu ülkeye kayıtlı adres yok'));
    }

    public function test_the_allowed_tab_says_allowed_addresses_pass_a_country_block(): void
    {
        Livewire::test(IpBlocksIndex::class)->set('tab', 'allowed')->assertSee('ülke engeli');
    }
}
