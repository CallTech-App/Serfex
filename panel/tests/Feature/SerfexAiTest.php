<?php

namespace Tests\Feature;

use App\Livewire\Ai\AiAssistant;
use App\Livewire\Ai\AiAudit;
use App\Livewire\Ai\AiDrawer;
use App\Livewire\Ai\AiSettingsPage;
use App\Livewire\Monitor\LiveMonitor;
use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\AiEvent;
use App\Models\AiUsage;
use App\Models\DnsZone;
use App\Models\IpBlock;
use App\Models\ResourceLimit;
use App\Models\Setting;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Ai\AiSettings;
use App\Services\Ai\Assistant;
use App\Services\Ai\ClaudeClient;
use App\Services\Ai\Transcript;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Serfex AI against a fake Claude API: the HTTP client answers with
 * scripted Messages API responses, so no real key or network is needed.
 */
class SerfexAiTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'sk-ant-api03-test-key-0000000000000000';

    private FakeAgent $agent;

    private MockHandler $claude;

    /** @var list<array{request: RequestInterface}> */
    private array $requests = [];

    private User $admin;

    private Site $shop;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Sercan']);
        $this->actingAs($this->admin);
        $this->shop = Site::factory()->for(SystemUser::factory()->create(['name' => 'magaza']), 'systemUser')->create(['domain' => 'magazaonline.com.tr']);

        $this->agent = new FakeAgent([
            'monitor.live' => fn () => $this->snapshot(),
            'firewall.block' => fn ($p) => ['address' => $p['address']],
            'firewall.unblock' => [],
            'site.logs' => fn ($p) => ['kind' => $p['kind'], 'exists' => true, 'size' => 120, 'truncated' => false,
                'content' => "45.155.205.233 - - [19/Oct/2026:10:00:01 +0300] \"POST /wp-login.php HTTP/1.1\" 404 0\n88.230.149.171 - - [19/Oct/2026:10:00:02 +0300] \"GET / HTTP/1.1\" 200 512\n"],
        ]);
        $this->app->instance(AgentClient::class, $this->agent);

        $this->claude = new MockHandler;
        $stack = HandlerStack::create($this->claude);
        $stack->push(Middleware::history($this->requests));
        $this->app->instance(ClaudeClient::class, new ClaudeClient(app(AiSettings::class), new Guzzle(['handler' => $stack])));

        app(AiSettings::class)->setApiKey(self::KEY);
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'at' => now()->toIso8601String(), 'window' => 10, 'interval' => 2,
            'server' => ['cpus' => 4, 'memory_total' => 8 << 30, 'load' => [3.5, 2.4, 1.3], 'uptime' => 90000, 'cpu' => 80, 'memory' => 3 << 30],
            'sites' => [[
                'domain' => 'magazaonline.com.tr', 'user' => 'magaza', 'cpu' => 95.0, 'memory' => 300 << 20, 'io' => 0, 'processes' => 12,
                'php' => ['known' => true, 'busy' => 10, 'workers' => 10, 'max' => 10, 'queue' => 20, 'max_reached' => true],
                'requests' => 160, 'client_errors' => 9, 'server_errors' => 0,
                'db' => ['connections' => 0, 'active' => 0, 'longest' => 0], 'mail_per_hour' => 0,
                'top_ips' => [['ip' => '45.155.205.233', 'requests' => 620]], 'limit' => null,
            ]],
            'accounts' => [], 'services' => [], 'databases' => [],
            'suspects' => [
                ['ip' => '45.155.205.233', 'target' => 'magazaonline.com.tr', 'kind' => 'login', 'detail' => '/wp-login.php', 'count' => 600, 'peak' => 600,
                    'since' => now()->subMinutes(3)->toIso8601String(), 'last' => now()->toIso8601String()],
            ],
        ];
    }

    /**
     * Queues one Messages API answer.
     *
     * @param  list<array<string, mixed>>  $content
     */
    private function answer(array $content, string $stop = 'end_turn', string $model = 'claude-opus-5-5'): void
    {
        $this->claude->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'msg_'.count($this->claude), 'type' => 'message', 'role' => 'assistant', 'model' => $model,
            'content' => $content, 'stop_reason' => $stop, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300, 'cache_read_input_tokens' => 5000, 'cache_creation_input_tokens' => 0],
        ])));
    }

    private function toolUse(string $id, string $name, array $input = []): array
    {
        return ['type' => 'tool_use', 'id' => $id, 'name' => $name, 'input' => $input === [] ? new \stdClass : $input];
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $i): array
    {
        return json_decode((string) $this->requests[$i]['request']->getBody(), true);
    }

    public function test_it_reads_blocks_an_attacker_by_itself_and_proposes_the_rest(): void
    {
        $zone = DnsZone::factory()->withRecords()->create(['name' => 'magazaonline.com.tr']);
        $this->answer([
            ['type' => 'text', 'text' => 'Bakıyorum.'],
            $this->toolUse('toolu_1', 'get_attacks'),
            $this->toolUse('toolu_2', 'read_site_log', ['domain' => 'magazaonline.com.tr', 'log' => 'access', 'contains' => 'wp-login']),
        ], 'tool_use');
        $this->answer([
            $this->toolUse('toolu_3', 'block_ip', ['address' => '45.155.205.233', 'minutes' => 60, 'reason' => 'wp-login.php kaba kuvvet', 'domain' => 'magazaonline.com.tr']),
            $this->toolUse('toolu_4', 'limit_site', ['domain' => 'magazaonline.com.tr', 'cpu_percent' => 100, 'minutes' => 240, 'reason' => 'Uzun süreli yük']),
            $this->toolUse('toolu_5', 'propose_action', ['action' => 'dns_add_record', 'reason' => 'mail için A kaydı eksik', 'domain' => 'magazaonline.com.tr',
                'record' => ['name' => 'mail', 'type' => 'A', 'content' => '203.0.113.10']]),
        ], 'tool_use');
        $this->answer([['type' => 'text', 'text' => "**45.155.205.233** adresi giriş sayfasına saldırıyordu; 1 saatliğine engelledim.\n\n<script>alert(1)</script>[tıkla](javascript:alert(1))"]]);

        $page = Livewire::test(AiAssistant::class)
            ->set('question', 'magazaonline.com.tr neden yavaş?')
            ->call('send');

        $conversation = AiConversation::sole();
        $this->assertSame('idle', $conversation->status);
        $this->assertSame('magazaonline.com.tr neden yavaş?', $conversation->title);
        $this->assertSame(['user', 'assistant', 'tool_results', 'assistant', 'tool_results', 'assistant'], $conversation->messages->pluck('kind')->all());

        // The request: cached, with the beta header for fallbacks, the
        // panel's tools, and the admin's question behind hidden context.
        $this->assertCount(3, $this->requests);
        $first = $this->body(0);
        $request = $this->requests[0]['request'];
        $this->assertSame(self::KEY, $request->getHeaderLine('x-api-key'));
        $this->assertStringContainsString('server-side-fallback', $request->getHeaderLine('anthropic-beta'));
        $this->assertSame('claude-opus-5-5', $first['model']);
        $this->assertSame(['type' => 'ephemeral'], $first['cache_control']);
        $this->assertSame('default', $first['fallbacks']);
        $this->assertSame('medium', $first['output_config']['effort']);
        $this->assertStringContainsString('Turkish', $first['system'][0]['text']);
        $this->assertContains('propose_action', array_column($first['tools'], 'name'));
        $this->assertStringContainsString('administrator Sercan, connected from 127.0.0.1', $first['messages'][0]['content'][0]['text']);
        $this->assertSame('magazaonline.com.tr neden yavaş?', $first['messages'][0]['content'][1]['text']);
        $this->assertStringNotContainsString(self::KEY, json_encode($first));

        // Tool results went back for every call.
        $results = collect($this->body(1)['messages'][2]['content']);
        $this->assertSame(['toolu_1', 'toolu_2'], $results->pluck('tool_use_id')->all());
        $this->assertStringContainsString('45.155.205.233', $results[0]['content']);
        $this->assertStringContainsString('wp-login.php', $results[1]['content']);
        $this->assertStringNotContainsString('88.230.149.171', $results[1]['content'], 'the contains filter applies');
        $this->assertSame('access', $this->agent->called('site.logs')[0]['kind']);
        $this->assertSame('magaza', $this->agent->called('site.logs')[0]['user']);

        // The block was applied by the AI itself, capped and time-boxed.
        $block = IpBlock::sole();
        $this->assertSame('45.155.205.233', $block->address);
        $this->assertSame('ai', $block->source);
        $this->assertSame($this->shop->id, $block->site_id);
        $this->assertEqualsWithDelta(60, now()->diffInMinutes($block->expires_at), 1);
        $this->assertSame(3600, $this->agent->called('firewall.block')[0]['seconds']);

        // A limit over two hours and the DNS change wait for the admin.
        $this->assertSame(0, ResourceLimit::count());
        $actions = AiAction::orderBy('id')->get();
        $this->assertSame(['auto', 'proposal', 'proposal'], $actions->pluck('kind')->all());
        $this->assertSame(['applied', 'pending', 'pending'], $actions->pluck('status')->all());
        $this->assertSame('45.155.205.233 adresini 1 saat engelle', $actions[0]->title);
        $this->assertSame('magazaonline.com.tr bölgesine mail.magazaonline.com.tr A kaydı ekle', $actions[2]->title);
        $toolResults = collect($this->body(2)['messages'][4]['content']);
        $this->assertStringContainsString('Done without approval', $toolResults[0]['content']);
        $this->assertStringContainsString('Nothing has changed yet', $toolResults[1]['content']);

        // Usage and the audit log.
        $this->assertSame(3, AiUsage::count());
        $this->assertSame(['read', 'read', 'auto', 'proposed', 'proposed'], AiEvent::orderBy('id')->pluck('type')->all());
        $this->assertSame('Site günlüğünü okudu: magazaonline.com.tr', AiEvent::where('tool', 'read_site_log')->value('summary'));

        // What the page shows: steps, cards and the answer without the
        // markup Claude wrote.
        $page->assertSee('Saldırılar')
            ->assertSee('Günlük · magazaonline.com.tr')
            ->assertSee('Serfex AI kendisi yaptı')
            ->assertSee('45.155.205.233 adresini 1 saat engelle')
            ->assertSee('+ mail.magazaonline.com.tr A 203.0.113.10')
            ->assertSee('Onayla ve uygula')
            ->assertSeeHtml('<strong>45.155.205.233</strong>')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertDontSeeHtml('href="javascript:');

        // Applying the DNS proposal changes the zone, and Undo restores it.
        $page->call('apply', $actions[2]->id)->assertDispatched('toast');
        $this->assertSame('applied', $actions[2]->refresh()->status);
        $this->assertSame($this->admin->id, $actions[2]->user_id);
        $this->assertTrue($zone->records()->where('name', 'mail')->where('type', 'A')->exists());
        $page->call('undo', $actions[2]->id);
        $this->assertSame('undone', $actions[2]->refresh()->status);
        $this->assertFalse($zone->records()->where('name', 'mail')->exists());
        $this->assertSame(4, $zone->records()->count());

        // Cancelling the limit proposal changes nothing.
        $page->call('reject', $actions[1]->id);
        $this->assertSame('rejected', $actions[1]->refresh()->status);
        $this->assertSame(0, ResourceLimit::count());

        // Undoing the AI's own block lifts it.
        $page->call('undo', $actions[0]->id);
        $this->assertFalse($block->refresh()->isActive());
        $this->assertSame(['read', 'read', 'auto', 'proposed', 'proposed', 'applied', 'undone', 'rejected', 'undone'], AiEvent::orderBy('id')->pluck('type')->all());

        // The next question tells Claude what was decided meanwhile.
        $this->answer([['type' => 'text', 'text' => 'Tamam.']]);
        $page->set('question', 'Teşekkürler')->call('send');
        $last = collect($this->body(3)['messages'])->last();
        $this->assertStringContainsString('was cancelled', $last['content'][0]['text']);
        $this->assertStringContainsString('applied, then undone', $last['content'][0]['text']);
    }

    public function test_the_admins_own_address_and_long_blocks_are_never_applied_by_the_ai(): void
    {
        $this->answer([
            $this->toolUse('toolu_1', 'block_ip', ['address' => '88.230.149.171', 'minutes' => 60, 'reason' => 'Deneme']),
            $this->toolUse('toolu_2', 'block_ip', ['address' => '198.51.100.7', 'minutes' => 2880, 'reason' => 'Uzun']),
            $this->toolUse('toolu_3', 'limit_site', ['domain' => 'magazaonline.com.tr', 'memory_mb' => 4096, 'minutes' => 60, 'reason' => 'Büyük']),
            $this->toolUse('toolu_4', 'limit_site', ['domain' => 'magazaonline.com.tr', 'php_workers' => 4, 'minutes' => 60, 'reason' => 'Yük']),
        ], 'tool_use');
        $this->answer([['type' => 'text', 'text' => 'Bitti.']]);

        app(Assistant::class)->ask($this->admin, 'Saldırı var mı?', clientIp: '88.230.149.171');

        $results = collect($this->body(1)['messages'][2]['content'])->keyBy('tool_use_id');
        $this->assertTrue($results['toolu_1']['is_error']);
        $this->assertStringContainsString('sizin bağlantınızın adresi', $results['toolu_1']['content']);
        // The admin's own address is refused; a 2-day block, 4 GB of memory
        // and anything else past the AI's caps wait for approval.
        $actions = AiAction::orderBy('id')->get();
        $this->assertSame(['auto', 'proposal', 'proposal', 'auto'], $actions->pluck('kind')->all());
        $this->assertSame(['failed', 'pending', 'pending', 'applied'], $actions->pluck('status')->all());
        $this->assertSame([], array_column($this->agent->called('firewall.block'), 'address'));
        $limit = ResourceLimit::sole();
        $this->assertSame('ai', $limit->source);
        $this->assertSame(4, $limit->php_workers);
    }

    public function test_with_auto_actions_off_blocks_become_proposals(): void
    {
        Setting::put('ai.auto_actions', false);
        $this->answer([$this->toolUse('toolu_1', 'block_ip', ['address' => '45.155.205.233', 'reason' => 'Saldırı'])], 'tool_use');
        $this->answer([['type' => 'text', 'text' => 'Önerdim.']]);

        app(Assistant::class)->ask($this->admin, 'Engelle');

        $this->assertSame(0, IpBlock::count());
        $action = AiAction::sole();
        $this->assertSame('proposal', $action->kind);

        // The admin applies it: the block is theirs, not the AI's.
        Livewire::test(AiAssistant::class, ['conversationId' => $action->ai_conversation_id])->call('apply', $action->id);
        $block = IpBlock::sole();
        $this->assertSame('panel', $block->source);
        $this->assertSame($this->admin->id, $block->user_id);
    }

    public function test_api_errors_are_explained_and_can_be_retried(): void
    {
        $this->claude->append(new Response(401, ['Content-Type' => 'application/json'], json_encode([
            'type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key'],
        ])));

        $page = Livewire::test(AiAssistant::class)->set('question', 'Merhaba')->call('send');

        $conversation = AiConversation::sole();
        $this->assertSame('failed', $conversation->status);
        $this->assertStringContainsString('Claude API anahtarı geçersiz', $conversation->error);
        $page->assertSee('Claude API anahtarı geçersiz')->assertSee('Yeniden dene');

        $this->answer([['type' => 'text', 'text' => 'Merhaba, nasıl yardımcı olabilirim?']]);
        $page->call('retry');
        $this->assertSame('idle', $conversation->refresh()->status);
        $this->assertSame(['user', 'assistant'], $conversation->messages->pluck('kind')->all());
    }

    public function test_an_interrupted_tool_call_is_repaired_before_the_next_request(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->admin->id, 'title' => 'Yarım']);
        $conversation->messages()->create(['kind' => 'user', 'text' => 'Siteler?', 'content' => [['type' => 'text', 'text' => 'Siteler?']]]);
        $conversation->messages()->create(['kind' => 'assistant', 'stop_reason' => 'tool_use', 'content' => [
            ['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'list_sites', 'input' => []],
        ]]);
        $this->answer([['type' => 'text', 'text' => 'Tamam.']]);

        app(Assistant::class)->run($conversation);

        $messages = $this->body(0)['messages'];
        $this->assertSame('toolu_x', $messages[2]['content'][0]['tool_use_id']);
        $this->assertTrue($messages[2]['content'][0]['is_error']);
        // An empty input goes back as a JSON object.
        $this->assertStringContainsString('"input":{}', (string) $this->requests[0]['request']->getBody());
    }

    public function test_the_monthly_budget_and_disabled_switch_stop_requests(): void
    {
        Setting::put('ai.budget', 5);
        AiUsage::create(['user_id' => $this->admin->id, 'model' => 'claude-opus-5-5', 'input_tokens' => 1, 'output_tokens' => 1,
            'cache_read_tokens' => 0, 'cache_write_tokens' => 0, 'cost' => 5.5, 'created_at' => now()]);

        $conversation = app(Assistant::class)->ask($this->admin, 'Merhaba');

        $this->assertSame('failed', $conversation->refresh()->status);
        $this->assertStringContainsString('harcama sınırına', $conversation->error);
        $this->assertCount(0, $this->requests);

        Setting::put('ai.enabled', false);
        Livewire::test(AiAssistant::class)->assertSee('Serfex AI kapalı');
    }

    public function test_settings_store_the_key_encrypted_and_check_it(): void
    {
        Setting::put('ai.key', null);

        Livewire::test(AiSettingsPage::class)
            ->assertSee('Claude API anahtarı')
            ->set('key', 'not-a-key')
            ->call('save')
            ->assertHasErrors('key')
            ->set('key', self::KEY)
            ->set('model', 'claude-sonnet-5-5')
            ->set('budget', '25')
            ->set('auto', false)
            ->call('save')
            ->assertHasNoErrors()
            ->assertDispatched('toast');

        $settings = app(AiSettings::class);
        $this->assertSame(self::KEY, $settings->apiKey());
        $this->assertStringNotContainsString(self::KEY, json_encode(Setting::all()));
        $this->assertSame('claude-sonnet-5-5', $settings->model());
        $this->assertSame(25.0, $settings->budget());
        $this->assertFalse($settings->autoActions());

        // The page shows the key masked, never in full.
        $this->get('/ai/settings')->assertOk()->assertSee('sk-ant-…0000')->assertDontSee(self::KEY);

        $this->claude->append(new Response(200, ['Content-Type' => 'application/json'], json_encode([
            'id' => 'claude-sonnet-5-5', 'type' => 'model', 'display_name' => 'Claude Sonnet 5.5', 'created_at' => '2026-09-01T00:00:00Z',
        ])));
        Livewire::test(AiSettingsPage::class)->call('test')->assertSee('Bağlantı çalışıyor: Claude Sonnet 5.5 kullanılabilir.');
        $this->assertStringEndsWith('/v1/models/claude-sonnet-5-5', $this->requests[0]['request']->getUri()->getPath());

        Livewire::test(AiSettingsPage::class)->set('model', 'gpt-4')->call('save')->assertHasErrors('model');

        Livewire::test(AiSettingsPage::class)->call('removeKey');
        $this->assertNull($settings->apiKey());
    }

    public function test_settings_show_this_months_usage(): void
    {
        AiUsage::create(['user_id' => $this->admin->id, 'model' => 'claude-opus-5-5', 'input_tokens' => 12000, 'output_tokens' => 3400,
            'cache_read_tokens' => 50000, 'cache_write_tokens' => 0, 'cost' => 0.1260, 'created_at' => now()]);

        $this->get('/ai/settings')->assertOk()->assertSee('$0,13')->assertSee('62.000')->assertSee('3.400');
    }

    public function test_the_audit_log_lists_and_filters_events(): void
    {
        $conversation = AiConversation::create(['user_id' => $this->admin->id, 'title' => 'Saldırı']);
        AiEvent::record('read', 'Saldırıları ve engelleri okudu', $conversation, tool: 'get_attacks');
        AiEvent::record('auto', '45.155.205.233 adresini 1 saat engelle', $conversation, tool: 'block_ip');
        AiEvent::record('applied', 'mail A kaydı ekle', $conversation, $this->admin, 'dns_add_record');

        $this->get('/ai/kayit')->assertOk()->assertSee('Saldırıları ve engelleri okudu')->assertSee('Kendisi yaptı')->assertSee('Sercan');

        Livewire::test(AiAudit::class)
            ->set('type', 'changes')
            ->assertDontSee('Saldırıları ve engelleri okudu')
            ->assertSee('45.155.205.233 adresini 1 saat engelle')
            ->assertSee('mail A kaydı ekle')
            ->set('type', '')
            ->set('search', 'mail')
            ->assertSee('mail A kaydı ekle')
            ->assertDontSee('45.155.205.233 adresini');
    }

    public function test_ask_ai_buttons_open_the_drawer_with_a_new_question(): void
    {
        Livewire::test(LiveMonitor::class)->call('setTab', 'saldirilar')->assertSee("Bunu AI'ya sor");

        $this->answer([['type' => 'text', 'text' => 'Saldırı engellenmeli.']]);
        Livewire::test(AiDrawer::class)
            ->dispatch('ai-ask', text: 'Saldırıları açıkla')
            ->assertSet('open', true)
            ->assertSee('Saldırı engellenmeli.');
        $this->assertSame('Saldırıları açıkla', AiConversation::sole()->title);
    }

    public function test_conversations_are_private_and_can_be_deleted_with_undo(): void
    {
        $other = User::factory()->create();
        $theirs = AiConversation::create(['user_id' => $other->id, 'title' => 'Başkasının sohbeti']);
        $mine = AiConversation::create(['user_id' => $this->admin->id, 'title' => 'Benim sohbetim']);

        $page = Livewire::test(AiAssistant::class)
            ->assertSee('Benim sohbetim')
            ->assertDontSee('Başkasının sohbeti')
            ->call('open', $theirs->id)
            ->assertSet('conversationId', null);

        $page->call('delete', $mine->id)->assertDispatched('toast');
        $this->assertSoftDeleted($mine);
        $page->call('restore', $mine->id);
        $this->assertNotSoftDeleted($mine);
    }

    public function test_only_administrators_reach_serfex_ai(): void
    {
        $this->actingAs(User::factory()->member()->create());

        $this->get('/ai')->assertForbidden();
        $this->get('/ai/settings')->assertForbidden();
        $this->get('/ai/kayit')->assertForbidden();
    }

    public function test_pages_render(): void
    {
        $this->get('/ai')->assertOk()->assertSee('Serfex AI')->assertSee('Size nasıl yardımcı olabilirim?');
        $this->get('/')->assertOk()->assertSee('Serfex AI');
    }

    public function test_markdown_is_rendered_without_raw_html(): void
    {
        $html = Transcript::markdown("# Başlık\n\n| a | b |\n|---|---|\n| 1 | 2 |\n\n<img src=x onerror=alert(1)>");

        $this->assertStringContainsString('<table>', $html);
        $this->assertStringNotContainsString('<img', $html);
    }
}
