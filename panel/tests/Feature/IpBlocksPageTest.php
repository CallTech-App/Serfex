<?php

namespace Tests\Feature;

use App\Livewire\QuickSearch;
use App\Livewire\Security\IpBlocksIndex;
use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\Site;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Agent\AgentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik > IP engelleri, the page: the list of blocks in force, bulk
 * blocking from a pasted list, bulk actions on selected rows, the allowed
 * addresses, the history, and Undo for each.
 */
class IpBlocksPageTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Sercan Akın']);
        $this->actingAs($this->admin);
        $this->agent = new FakeAgent([
            'firewall.block' => fn ($p) => ['address' => $p['address']],
            'firewall.block.many' => fn ($p) => ['blocked' => array_map(fn ($i) => ['address' => $i['address']], $p['items']), 'skipped' => []],
            'firewall.unblock.many' => fn ($p) => ['removed' => count($p['addresses'])],
            'firewall.allow.apply' => fn ($p) => ['allowed' => $p['addresses'], 'own' => ['203.0.113.10', '2001:db8::10']],
            'firewall.allow.list' => fn () => ['allowed' => IpAllow::pluck('address')->all(), 'own' => ['203.0.113.10', '2001:db8::10']],
            'geo.lookup' => fn ($p) => ['loaded' => true, 'countries' => array_fill_keys($p['addresses'], 'NL')],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_the_page_is_for_administrators(): void
    {
        $this->get('/security/ip-blocks')->assertOk()->assertSee('IP engelleri')->assertSee('Engelli adresler')->assertSee('Geçmiş');

        $this->actingAs(User::factory()->member()->create());
        $this->get('/security/ip-blocks')->assertForbidden();
    }

    public function test_the_list_shows_blocks_in_force_with_country_reason_scope_and_time_left(): void
    {
        $site = Site::factory()->create(['domain' => 'magazaonline.com.tr']);
        $forever = IpBlock::create(['address' => '45.155.205.233', 'source' => 'panel', 'user_id' => $this->admin->id, 'site_id' => $site->id, 'reason' => 'Giriş sayfasına dakikada 84 deneme']);
        IpBlock::create(['address' => '185.220.101.47', 'source' => 'ai', 'reason' => 'Dakikada 1.240 istek', 'expires_at' => now()->addMinutes(46)]);
        IpBlock::create(['address' => '103.153.79.6', 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'Çok sayıda hatalı SSH girişi', 'expires_at' => now()->addHour()]);
        IpBlock::create(['address' => '198.51.100.1', 'source' => 'panel', 'expires_at' => now()->subMinute()]);
        IpBlock::create(['address' => '198.51.100.2', 'source' => 'panel', 'removed_at' => now()]);

        Livewire::test(IpBlocksIndex::class)
            ->assertSee('45.155.205.233')->assertSee('Giriş sayfasına dakikada 84 deneme')->assertSee('magazaonline.com.tr')->assertSee('Sercan Akın')->assertSee('Kalıcı')
            ->assertSee('185.220.101.47')->assertSee('Serfex AI')->assertSee('46 dk')
            ->assertSee('103.153.79.6')->assertSee('Yalnızca SSH portu')
            ->assertSee('Hollanda')
            ->assertDontSee('198.51.100.1')->assertDontSee('198.51.100.2');

        // The country is looked up once and kept on the row.
        $this->assertSame('NL', $forever->fresh()->country);
        $this->assertCount(1, $this->agent->called('geo.lookup'));
        Livewire::test(IpBlocksIndex::class)->assertSee('Hollanda');
        $this->assertCount(1, $this->agent->called('geo.lookup'));
    }

    public function test_the_list_is_filtered_by_source_and_searched_by_address_reason_or_country(): void
    {
        IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'reason' => 'Kötü bot listesi', 'country' => 'RU']);
        IpBlock::create(['address' => '192.0.2.2', 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'SSH', 'country' => 'VN']);
        IpBlock::create(['address' => '198.51.100.3', 'source' => 'ai', 'reason' => 'Dakikada 500 istek', 'country' => 'DE']);

        Livewire::test(IpBlocksIndex::class)
            ->set('source', 'fail2ban')->assertSee('192.0.2.2')->assertDontSee('192.0.2.1')->assertDontSee('198.51.100.3')
            ->set('source', '')->set('search', '198.51')->assertSee('198.51.100.3')->assertDontSee('192.0.2.1')
            ->set('search', 'bot liste')->assertSee('192.0.2.1')->assertDontSee('192.0.2.2')
            ->set('search', 'rusya')->assertSee('192.0.2.1')->assertDontSee('198.51.100.3')
            ->set('search', 'vn')->assertSee('192.0.2.2')->assertDontSee('192.0.2.1')
            ->set('search', 'hiçbiri')->assertSee('Aramanızla eşleşen engel yok.');

        // An address inside a blocked range is found by the range.
        IpBlock::create(['address' => '203.0.113.0/24', 'source' => 'panel', 'country' => 'US']);
        Livewire::test(IpBlocksIndex::class)->set('search', '203.0.113.77')->assertSee('203.0.113.0/24')->assertDontSee('engelli değil');
    }

    public function test_an_address_that_is_not_blocked_can_be_looked_at_or_blocked_from_the_search(): void
    {
        Livewire::test(IpBlocksIndex::class)
            ->set('search', '198.51.100.77')
            ->assertSeeText('198.51.100.77 engelli değil.')->assertSee('Adresi incele')->assertSeeHtml(route('security.blocks.address', '198.51.100.77'))
            ->call('openPaste', '198.51.100.77')
            ->assertSet('pasting', true)->assertSet('paste', '198.51.100.77');
    }

    public function test_the_list_shows_twenty_five_addresses_a_page(): void
    {
        foreach (range(1, 30) as $i) {
            IpBlock::forceCreate(['address' => '192.0.2.'.$i, 'source' => 'panel', 'country' => 'NL', 'created_at' => now()->subMinutes($i), 'updated_at' => now()]);
        }

        Livewire::test(IpBlocksIndex::class)
            ->assertSee('1–25 / 30')->assertSee('192.0.2.25')->assertDontSee('192.0.2.26')
            ->call('nextPage')
            ->assertSee('26–30 / 30')->assertSee('192.0.2.26')->assertDontSeeHtml('>192.0.2.1<');
    }

    public function test_a_pasted_list_is_checked_before_anything_is_blocked(): void
    {
        IpBlock::create(['address' => '185.220.101.47', 'source' => 'ai', 'expires_at' => now()->addHours(3)]);
        IpAllow::create(['address' => '198.51.100.0/24']);

        Livewire::test(IpBlocksIndex::class)
            ->call('openPaste')
            ->assertSet('minutes', 1440)
            ->set('paste', "45.9.150.221\r\n45.9.148.0/24, 2a0b:f4c0:16c:4::1 # bots\n185.220.101.47\n198.51.100.9\n62.210.0.0/12\nbozuk-satir\n45.9.150.221")
            ->assertSee('3 yeni adres engellenecek')
            ->assertSee('zaten engelli')
            ->assertSee('3 satır atlanacak')
            ->assertSee('izinli adreslerde (198.51.100.0/24)')->assertSee('aralık çok geniş')->assertSee('bir IP adresi değil')
            ->assertSee('4 adresi engelle');

        $this->assertSame([], $this->agent->called('firewall.block.many'));
        $this->assertSame(1, IpBlock::count());
    }

    public function test_a_pasted_list_is_blocked_with_one_call_and_can_be_undone(): void
    {
        $known = IpBlock::create(['address' => '185.220.101.47', 'source' => 'ai', 'reason' => 'Dakikada 980 istek', 'expires_at' => $until = now()->addHours(3)->startOfSecond()]);

        $page = Livewire::test(IpBlocksIndex::class)
            ->call('openPaste')
            ->set('paste', "45.9.150.221\n45.9.148.0/24;185.220.101.47\tbozuk")
            ->set('minutes', 10080)
            ->set('reason', 'Kötü bot listesi (19 Kas)')
            ->call('saveBlocks')
            ->assertHasNoErrors()
            ->assertSet('pasting', false)
            ->assertDispatched('toast', message: '2 adres engellendi, 1 adresin süresi değiştirildi.');

        $calls = $this->agent->called('firewall.block.many');
        $this->assertCount(1, $calls);
        $this->assertSame(['45.9.150.221', '45.9.148.0/24', '185.220.101.47'], array_column($calls[0]['items'], 'address'));
        $this->assertSame([604800, 604800, 604800], array_column($calls[0]['items'], 'seconds'));
        $new = IpBlock::where('address', '45.9.148.0/24')->sole();
        $this->assertSame('Kötü bot listesi (19 Kas)', $new->reason);
        $this->assertSame($this->admin->id, $new->user_id);
        $this->assertEqualsWithDelta(now()->addDays(7)->timestamp, $new->expires_at->timestamp, 5);
        $this->assertSame('panel', $known->fresh()->source);

        $page->call('undo', $this->undoToken($page))->assertDispatched('toast', message: 'Geri alındı.');

        // The new blocks are gone without a trace, the old one is as it was.
        $this->assertSame([$known->id], IpBlock::pluck('id')->all());
        $this->assertEqualsCanonicalizing(['45.9.150.221', '45.9.148.0/24'], $this->agent->called('firewall.unblock.many')[0]['addresses']);
        $known->refresh();
        $this->assertSame('ai', $known->source);
        $this->assertSame('Dakikada 980 istek', $known->reason);
        $this->assertTrue($known->expires_at->equalTo($until));
    }

    public function test_nothing_is_stored_and_the_dialog_says_why_when_the_agent_cannot_block(): void
    {
        $this->agent->answers['firewall.block.many'] = AgentException::rpcError('firewall.block.many', AgentException::OPERATION_FAILED, 'nft: Error: Could not process rule: No such file or directory');

        Livewire::test(IpBlocksIndex::class)
            ->call('openPaste')
            ->set('paste', "45.9.150.221\n45.9.148.0/24")
            ->call('saveBlocks')
            ->assertHasErrors('paste')
            ->assertSee('Adresler engellenemedi')->assertSee('Could not process rule')
            ->assertSet('pasting', true)
            ->assertNotDispatched('toast');

        $this->assertSame(0, IpBlock::count());
    }

    public function test_a_list_with_nothing_to_block_or_too_many_addresses_is_refused(): void
    {
        $page = Livewire::test(IpBlocksIndex::class)->call('openPaste')
            ->set('paste', "bozuk\n# yalnızca not")->call('saveBlocks')->assertHasErrors('paste')->assertSee('Listede engellenecek adres yok.');

        $many = implode("\n", array_map(fn ($i) => '45.9.'.intdiv($i, 250).'.'.($i % 250 + 1), range(0, 1000)));
        $page->set('paste', $many)->assertSee('Bir kerede en fazla 1.000 adres engellenebilir')
            ->call('saveBlocks')->assertHasErrors('paste');

        $this->assertSame([], $this->agent->called('firewall.block.many'));
    }

    public function test_the_callers_own_address_is_left_out_of_a_pasted_list(): void
    {
        $this->connectFrom('88.230.14.52');

        Livewire::test(IpBlocksIndex::class)
            ->call('openPaste')
            ->set('paste', "88.230.14.52\n45.9.150.221")
            ->assertSee('şu anki bağlantınızın adresi')
            ->call('saveBlocks')
            ->assertDispatched('toast', message: '45.9.150.221 engellendi.');

        $this->assertSame(['45.9.150.221'], IpBlock::pluck('address')->all());
    }

    public function test_selected_blocks_are_lifted_and_the_toast_counts_what_was_really_lifted(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'expires_at' => now()->addHour()]);
        $b = IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel']);
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);
        $gone = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'expires_at' => now()->addHour()]);

        $page = Livewire::test(IpBlocksIndex::class)->set('selected', [$a->id, $b->id, $ban->id, $gone->id]);
        // One of them runs out while the page is open.
        $gone->update(['expires_at' => now()->subSecond()]);
        $page->call('unblockSelected')
            ->assertSet('selected', [])
            ->assertDispatched('toast', message: '3 adresin engeli kaldırıldı.');

        $this->assertSame(['192.0.2.1', '192.0.2.2'], $this->agent->called('firewall.unblock.many')[0]['addresses']);
        $this->assertSame([['jail' => 'sshd', 'address' => '192.0.2.3']], $this->agent->called('fail2ban.unban'));
        $this->assertSame('user', $a->fresh()->ended);
        $this->assertSame($this->admin->id, $a->fresh()->removed_by);
        $this->assertNull($gone->fresh()->removed_at);

        $page->call('undo', $this->undoToken($page))->assertDispatched('toast', message: 'Geri alındı.');
        $this->assertTrue($a->fresh()->isActive());
        $this->assertTrue($b->fresh()->isActive());
        $this->assertTrue($ban->fresh()->isActive());
        $this->assertSame([['jail' => 'sshd', 'address' => '192.0.2.3']], $this->agent->called('fail2ban.ban'));
    }

    public function test_one_block_is_lifted_from_its_row(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);

        $page = Livewire::test(IpBlocksIndex::class)->call('unblock', $a->id)
            ->assertDispatched('toast', message: '192.0.2.1 adresinin engeli kaldırıldı.');
        $this->assertFalse($a->fresh()->isActive());

        // Pressed again in another tab: nothing to do, nothing claimed.
        Livewire::test(IpBlocksIndex::class)->call('unblock', $a->id)->assertNotDispatched('toast');

        $page->call('undo', $this->undoToken($page));
        $this->assertTrue($a->fresh()->isActive());
    }

    public function test_an_agent_failure_while_lifting_is_shown_and_nothing_changes(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        $this->agent->answers['firewall.unblock.many'] = AgentException::rpcError('firewall.unblock.many', AgentException::OPERATION_FAILED, 'nft: permission denied');

        Livewire::test(IpBlocksIndex::class)->set('selected', [$a->id])->call('unblockSelected')
            ->assertSee('Engel kaldırılamadı')->assertSee('permission denied')
            ->assertNotDispatched('toast');

        $this->assertTrue($a->fresh()->isActive());
    }

    public function test_every_address_of_the_filtered_list_can_be_selected_beyond_the_page(): void
    {
        foreach (range(1, 30) as $i) {
            IpBlock::create(['address' => '192.0.2.'.$i, 'source' => 'panel', 'reason' => 'Kötü bot listesi', 'country' => 'NL']);
        }
        $other = IpBlock::create(['address' => '198.51.100.1', 'source' => 'ai', 'country' => 'NL']);

        $page = Livewire::test(IpBlocksIndex::class)->set('source', 'panel')
            ->call('selectPage', true)
            ->assertCount('selected', 25)
            ->assertSee('25 adres seçildi.')->assertSee('Listedeki 30 adresin tümünü seç')
            ->call('selectAllMatching')
            ->assertSee('Listedeki 30 adresin tümü seçildi.')
            ->call('unblockSelected')
            ->assertDispatched('toast', message: '30 adresin engeli kaldırıldı.');

        $this->assertSame(0, IpBlock::active()->where('source', 'panel')->count());
        $this->assertTrue($other->fresh()->isActive());
        $this->assertCount(30, $this->agent->called('firewall.unblock.many')[0]['addresses']);

        // Changing the filter drops the selection.
        $page->call('selectPage', true)->set('source', 'ai')->assertSet('selected', [])->assertSet('allMatching', false);
    }

    public function test_selected_addresses_move_to_the_allowed_list(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'expires_at' => now()->addHour()]);

        $page = Livewire::test(IpBlocksIndex::class)->set('selected', [$a->id, $ban->id])
            ->call('allowSelected')
            ->assertSet('selected', [])
            ->assertDispatched('toast', message: '2 adres izinlilere eklendi.');

        $this->assertEqualsCanonicalizing(['192.0.2.1', '192.0.2.3'], IpAllow::pluck('address')->all());
        $this->assertSame($this->admin->id, IpAllow::first()->user_id);
        $this->assertSame('allowed', $a->fresh()->ended);
        $this->assertSame('allowed', $ban->fresh()->ended);

        $page->call('undo', $this->undoToken($page));
        $this->assertSame(0, IpAllow::count());
        $this->assertSame([], last($this->agent->called('firewall.allow.apply'))['addresses']);
        $this->assertTrue($a->fresh()->isActive());
        $this->assertTrue($ban->fresh()->isActive());
    }

    public function test_the_duration_of_selected_blocks_is_changed_and_a_fail2ban_ban_becomes_a_block_on_the_whole_server(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'ai', 'reason' => 'Dakikada 500 istek', 'expires_at' => $until = now()->addHours(2)->startOfSecond()]);
        $ban = IpBlock::create(['address' => '192.0.2.3', 'source' => 'fail2ban', 'kind' => 'sshd', 'reason' => 'SSH', 'expires_at' => now()->addHour()]);

        $page = Livewire::test(IpBlocksIndex::class)->set('selected', [$a->id, $ban->id])
            ->call('changeSelected', 0)
            ->assertSet('selected', [])
            ->assertDispatched('toast', message: '2 engelin süresi değiştirildi: Kalıcı.');

        $this->assertNull($a->fresh()->expires_at);
        $this->assertSame('ai', $a->fresh()->source);
        $this->assertSame('replaced', $ban->fresh()->ended);
        $server = IpBlock::active()->where('address', '192.0.2.3')->sole();
        $this->assertSame('panel', $server->source);
        $this->assertNull($server->expires_at);

        $page->call('undo', $this->undoToken($page));
        $this->assertTrue($a->fresh()->expires_at->equalTo($until));
        $this->assertTrue($ban->fresh()->isActive());
        $this->assertSame(0, IpBlock::where('address', '192.0.2.3')->where('source', 'panel')->count());

        // Only the durations the page offers are taken.
        Livewire::test(IpBlocksIndex::class)->set('selected', [$a->id])->call('changeSelected', 5)->assertNotDispatched('toast');
        $this->assertTrue($a->fresh()->expires_at->equalTo($until));
    }

    public function test_an_address_is_allowed_with_a_note_and_taken_off_the_list_again(): void
    {
        $blocked = IpBlock::create(['address' => '88.230.14.52', 'source' => 'fail2ban', 'kind' => 'serfex-panel', 'expires_at' => now()->addHour()]);

        $page = Livewire::test(IpBlocksIndex::class)->set('tab', 'allowed')
            ->assertSee('203.0.113.10')->assertSee('2001:db8::10')->assertSee('Sunucunun kendi adresi')->assertSee('Hollanda')
            ->call('openAllow')
            ->set('allowAddress', 'ofis')->call('saveAllow')->assertHasErrors('allowAddress')->assertSee('bir IP adresi değil')
            ->set('allowAddress', '203.0.113.10')->call('saveAllow')->assertHasErrors('allowAddress')->assertSee('sunucunun kendi adresi')
            ->set('allowAddress', ' 88.230.14.52 ')->set('allowNote', 'Ofis (sabit IP)')->call('saveAllow')
            ->assertHasNoErrors()->assertSet('allowing', false)
            ->assertDispatched('toast', message: '88.230.14.52 izinlilere eklendi, engeli kaldırıldı.')
            ->assertSee('Ofis (sabit IP)')->assertSee('Sercan Akın');

        $allow = IpAllow::sole();
        $this->assertSame('88.230.14.52', $allow->address);
        $this->assertSame('allowed', $blocked->fresh()->ended);
        $this->assertSame(['88.230.14.52'], last($this->agent->called('firewall.allow.apply'))['addresses']);

        // Undo takes the address off the list and brings the ban back.
        $page->call('undo', $this->undoToken($page));
        $this->assertSame(0, IpAllow::count());
        $this->assertTrue($blocked->fresh()->isActive());

        $allow = IpAllow::create(['address' => '34.74.90.64/28', 'note' => 'Ödeme sağlayıcısı', 'user_id' => $this->admin->id]);
        $page->set('selected', [$allow->id])->call('removeAllows')
            ->assertDispatched('toast', message: '34.74.90.64/28 izinlilerden kaldırıldı.');
        $this->assertSame(0, IpAllow::count());
        $page->call('undo', $this->undoToken($page));
        $this->assertSame(['Ödeme sağlayıcısı'], IpAllow::where('address', '34.74.90.64/28')->pluck('note')->all());
    }

    public function test_the_allowed_list_marks_the_address_the_reader_is_connected_from(): void
    {
        $this->connectFrom('88.230.14.52');
        IpAllow::create(['address' => '88.230.14.0/24', 'note' => 'Ofis']);
        IpAllow::create(['address' => '34.74.90.64/28']);

        $page = Livewire::test(IpBlocksIndex::class)->set('tab', 'allowed');
        // Newest first, the reader's own range marked, the server's addresses last.
        $html = $page->html();
        $at = array_map(fn (string $text) => strpos($html, $text), ['>34.74.90.64/28<', '>88.230.14.0/24<', 'Şu anki bağlantınız', '>203.0.113.10<']);
        $this->assertNotContains(false, $at);
        $sorted = $at;
        sort($sorted);
        $this->assertSame($sorted, $at);
        $this->assertSame(1, substr_count($html, 'Şu anki bağlantınız'));

        $page->set('search', 'ofis')->assertSee('88.230.14.0/24')->assertDontSee('34.74.90.64/28');
    }

    public function test_history_lists_ended_blocks_and_blocks_them_again_for_as_long_as_before(): void
    {
        $ranOut = IpBlock::forceCreate(['address' => '192.0.2.1', 'source' => 'ai', 'reason' => 'Dakikada 980 istek', 'created_at' => now()->subHours(30), 'updated_at' => now(), 'expires_at' => now()->subHours(6)]);
        $lifted = IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel', 'user_id' => $this->admin->id, 'removed_at' => now()->subHour(), 'ended' => 'user', 'removed_by' => $this->admin->id]);
        $again = IpBlock::create(['address' => '192.0.2.3', 'source' => 'panel', 'removed_at' => now()->subDays(2), 'ended' => 'user']);
        IpBlock::create(['address' => '192.0.2.3', 'source' => 'panel']);
        $allowed = IpBlock::create(['address' => '192.0.2.4', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now()->subDays(3), 'ended' => 'allowed']);
        IpAllow::create(['address' => '192.0.2.4']);
        $old = IpBlock::create(['address' => '192.0.2.5', 'source' => 'panel', 'removed_at' => now()->subDays(40), 'ended' => 'user']);
        $active = IpBlock::create(['address' => '192.0.2.6', 'source' => 'panel']);

        $page = Livewire::test(IpBlocksIndex::class)->set('tab', 'history')
            ->assertSee('192.0.2.1')->assertSee('Süresi doldu')
            ->assertSee('192.0.2.2')->assertSee('Sercan Akın kaldırdı')
            ->assertSee('Şu an engelli')->assertSee('İzinli')
            ->assertDontSee('192.0.2.5')->assertDontSee('192.0.2.6')
            ->set('days', 90)->assertSee('192.0.2.5')
            ->set('days', 30);

        $page->set('selected', [$ranOut->id, $lifted->id, $again->id, $allowed->id])
            ->call('blockAgain')
            ->assertSet('selected', [])
            ->assertDispatched('toast', message: '2 adres yeniden engellendi.');

        $new = IpBlock::active()->where('address', '192.0.2.1')->sole();
        $this->assertSame(1440, $new->minutes());
        $this->assertSame('Dakikada 980 istek', $new->reason);
        $this->assertSame('panel', $new->source);
        $this->assertNull(IpBlock::active()->where('address', '192.0.2.2')->sole()->expires_at);
        $this->assertSame(1, IpBlock::active()->where('address', '192.0.2.3')->count());
        $this->assertSame(0, IpBlock::active()->where('address', '192.0.2.4')->count());
        $this->assertTrue($active->fresh()->isActive());
        $this->assertNotNull($old->fresh()->removed_at);

        $page->call('undo', $this->undoToken($page));
        $this->assertSame(0, IpBlock::active()->whereIn('address', ['192.0.2.1', '192.0.2.2'])->count());

        $page->call('blockAgain', $lifted->id)->assertDispatched('toast', message: '192.0.2.2 yeniden engellendi.');
        $this->assertSame(1, IpBlock::active()->where('address', '192.0.2.2')->count());
    }

    public function test_undo_works_once_and_only_for_the_person_who_did_it(): void
    {
        $a = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        $page = Livewire::test(IpBlocksIndex::class)->call('unblock', $a->id);
        $token = $this->undoToken($page);

        $this->actingAs(User::factory()->create());
        Livewire::test(IpBlocksIndex::class)->call('undo', $token)->assertNotDispatched('toast', message: 'Geri alındı.');
        $this->assertFalse($a->fresh()->isActive());

        $this->actingAs($this->admin);
        Livewire::test(IpBlocksIndex::class)->call('undo', $token)->assertDispatched('toast', message: 'Geri alındı.');
        $this->assertTrue($a->fresh()->isActive());
        $calls = count($this->agent->called('firewall.block.many'));
        Livewire::test(IpBlocksIndex::class)->call('undo', $token)->assertNotDispatched('toast', message: 'Geri alındı.');
        $this->assertCount($calls, $this->agent->called('firewall.block.many'));
    }

    public function test_the_tabs_count_what_they_hold(): void
    {
        IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel']);
        IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel']);
        IpBlock::create(['address' => '192.0.2.3', 'source' => 'panel', 'removed_at' => now()]);
        IpAllow::create(['address' => '198.51.100.9']);

        $html = Livewire::test(IpBlocksIndex::class)->html();
        $this->assertMatchesRegularExpression('/Engelli adresler\s*(<!--.*?-->\s*)*<span[^>]*>2</', $html);
        // The server's two own addresses are on the allowed list too.
        $this->assertMatchesRegularExpression('/İzinli adresler\s*(<!--.*?-->\s*)*<span[^>]*>3</u', $html);

        // One page of allowed addresses: no "1–1 / 1" beside three rows.
        Livewire::test(IpBlocksIndex::class)->set('tab', 'allowed')->assertSee('198.51.100.9')->assertSee('203.0.113.10')->assertDontSee('1–1 / 1');
    }

    public function test_the_dashboard_opens_the_blocks_serfex_ai_made(): void
    {
        IpBlock::create(['address' => '185.220.101.47', 'source' => 'ai', 'reason' => 'Dakikada 1.240 istek', 'expires_at' => now()->addHours(3)]);

        $this->get('/')->assertOk()->assertSee('Engellere bak')
            ->assertSee('href="'.e(route('security.blocks', ['source' => 'ai'])).'"', false);
    }

    public function test_quick_search_finds_the_page_for_administrators_only(): void
    {
        $find = fn (string $q) => collect(Livewire::test(QuickSearch::class)->set('q', $q)->instance()->results())->firstWhere('group', 'Sayfalar')['items'] ?? [];

        $item = collect($find('engel'))->firstWhere('label', 'IP engelleri');
        $this->assertSame(route('security.blocks'), $item['url'] ?? null);
        $this->assertContains('IP engelleri', array_column($find('izinli adres'), 'label'));

        $this->actingAs(User::factory()->member()->create());
        $this->assertNotContains('IP engelleri', array_column($find('engel'), 'label'));
    }

    /**
     * Every request of the test comes from $address.
     */
    private function connectFrom(string $address): void
    {
        $this->app->rebinding('request', fn ($app, $request) => $request->server->set('REMOTE_ADDR', $address));
    }

    private function undoToken($page): string
    {
        $toast = collect($page->effects['dispatches'] ?? [])->last(fn ($d) => $d['name'] === 'toast' && isset($d['params']['undo']));
        $this->assertNotNull($toast, 'no toast with Undo was shown');

        return $toast['params']['undo']['args'][0];
    }
}
