<?php

namespace Tests\Feature;

use App\Models\AiAction;
use App\Models\AiConversation;
use App\Models\DnsZone;
use App\Models\ResourceLimit;
use App\Models\Site;
use App\Models\SystemUser;
use App\Models\User;
use App\Services\Agent\AgentClient;
use App\Services\Ai\Actions;
use App\Services\Ai\Models;
use App\Services\Monitor\MonitorException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * What Serfex AI may propose, and that applying and undoing a proposal goes
 * through the panel's own services.
 */
class SerfexAiActionsTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    private AiConversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create();
        $this->agent = new FakeAgent(['mail.queue.hold' => ['messages' => 12], 'mail.queue.release' => ['messages' => 12]]);
        $this->app->instance(AgentClient::class, $this->agent);
        Site::factory()->for(SystemUser::factory()->create(['name' => 'magaza']), 'systemUser')->create(['domain' => 'magazaonline.com.tr']);
        DnsZone::factory()->withRecords()->create(['name' => 'magazaonline.com.tr']);
        $this->conversation = AiConversation::create(['user_id' => $this->admin->id, 'title' => 'Test']);
    }

    private function actions(): Actions
    {
        return app(Actions::class);
    }

    /**
     * Records a proposal the way a propose_action call does.
     *
     * @param  array<string, mixed>  $input
     */
    private function propose(array $input): AiAction
    {
        [, $error] = $this->actions()->call('propose_action', $input + ['reason' => 'Test'], 'toolu_'.uniqid(), $this->conversation);
        $this->assertFalse($error);

        return AiAction::latest('id')->first();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalid(): array
    {
        return [
            'unknown action' => [['action' => 'run_shell', 'command' => 'rm -rf /'], 'Unknown action'],
            'unknown site' => [['action' => 'restart_php', 'domain' => 'yok.com'], 'No site named'],
            'limit without values' => [['action' => 'limit_site', 'domain' => 'magazaonline.com.tr', 'minutes' => 60], 'at least one'],
            'limit too long' => [['action' => 'limit_site', 'domain' => 'magazaonline.com.tr', 'cpu_percent' => 50, 'minutes' => 99999], 'minutes must be'],
            'redirect without certificate' => [['action' => 'https_redirect', 'domain' => 'magazaonline.com.tr', 'enabled' => true], 'no installed certificate'],
            'cname at apex' => [['action' => 'dns_add_record', 'domain' => 'magazaonline.com.tr', 'record' => ['name' => '@', 'type' => 'CNAME', 'content' => 'x.net.']], 'apex'],
            'duplicate record' => [['action' => 'dns_add_record', 'domain' => 'magazaonline.com.tr', 'record' => ['name' => '@', 'type' => 'A', 'content' => '203.0.113.10']], 'already exists'],
            'missing old record' => [['action' => 'dns_delete_record', 'domain' => 'magazaonline.com.tr', 'old_record' => ['name' => 'ftp', 'type' => 'A', 'content' => '203.0.113.10']], 'matches 0'],
            'no laravel app' => [['action' => 'laravel_deploy', 'domain' => 'magazaonline.com.tr'], 'no Laravel application'],
            'unblock not blocked' => [['action' => 'unblock_ip', 'address' => '192.0.2.1'], 'is not blocked'],
        ];
    }

    #[DataProvider('invalid')]
    public function test_invalid_proposals_are_refused_with_a_reason(array $input, string $message): void
    {
        [$result, $error] = $this->actions()->call('propose_action', $input + ['reason' => 'Test'], 'toolu_1', $this->conversation);

        $this->assertTrue($error);
        $this->assertStringContainsString($message, $result);
        $this->assertSame(0, AiAction::count());
        $this->assertSame([], $this->agent->calls);
    }

    public function test_a_dns_change_cannot_be_undone_after_the_zone_changed_again(): void
    {
        $zone = DnsZone::sole();
        $action = $this->propose(['action' => 'dns_update_record', 'domain' => 'magazaonline.com.tr',
            'old_record' => ['name' => '@', 'type' => 'A', 'content' => '203.0.113.10'],
            'record' => ['name' => '@', 'type' => 'A', 'content' => '203.0.113.20']]);
        $this->assertSame(['− magazaonline.com.tr A 203.0.113.10 (TTL 3600)', '+ magazaonline.com.tr A 203.0.113.20 (TTL 3600)'], Actions::details($action));

        $this->actions()->apply($action, $this->admin);
        $this->assertSame('203.0.113.20', $zone->records()->where('type', 'A')->value('content'));

        $zone->records()->create(['name' => 'blog', 'type' => 'A', 'content' => '203.0.113.30', 'ttl' => 3600]);
        try {
            $this->actions()->undo($action->refresh(), $this->admin);
            $this->fail('undo must refuse');
        } catch (MonitorException $e) {
            $this->assertStringContainsString('değişti', $e->getMessage());
        }
        $this->assertSame('applied', $action->refresh()->status);
    }

    public function test_undoing_a_limit_brings_back_the_one_before(): void
    {
        $site = Site::sole();
        $before = ResourceLimit::create(['site_id' => $site->id, 'cpu' => 50, 'source' => 'panel', 'reason' => 'Elle', 'expires_at' => now()->addHour()]);
        $action = $this->propose(['action' => 'limit_site', 'domain' => 'magazaonline.com.tr', 'memory_mb' => 1024, 'minutes' => 600]);

        $this->actions()->apply($action, $this->admin);
        $active = $site->refresh()->activeLimit();
        $this->assertSame(1024, $active->memory_mb);
        $this->assertSame('panel', $active->source);

        $this->actions()->undo($action->refresh(), $this->admin);
        $restored = $site->refresh()->activeLimit();
        $this->assertSame(50, $restored->cpu);
        $this->assertNotSame($before->id, $restored->id);
    }

    public function test_mail_hold_is_undone_by_releasing(): void
    {
        $action = $this->propose(['action' => 'mail_hold', 'domain' => 'magazaonline.com.tr']);
        $this->assertSame('magazaonline.com.tr kaynaklı kuyruktaki postaları beklet', $action->title);

        $this->actions()->apply($action, $this->admin);
        $this->actions()->undo($action->refresh(), $this->admin);

        $this->assertSame(['mail.queue.hold', 'mail.queue.release'], $this->agent->methods());
    }

    public function test_proposals_expire_and_are_decided_once(): void
    {
        $action = $this->propose(['action' => 'restart_php', 'domain' => 'magazaonline.com.tr']);
        $this->assertFalse(Actions::undoable('restart_php'));

        $this->travel(Actions::PROPOSAL_HOURS + 1)->hours();
        $this->expectException(MonitorException::class);
        $this->actions()->apply($action, $this->admin);
    }

    public function test_a_rejected_proposal_cannot_be_applied(): void
    {
        $action = $this->propose(['action' => 'mail_flush']);
        $this->actions()->reject($action, $this->admin);

        $this->expectExceptionMessage('zaten karara bağlandı');
        $this->actions()->apply($action->refresh(), $this->admin);
    }

    public function test_cost_uses_the_models_prices(): void
    {
        // 1M input at $4, 100k output at $20, 2M cache reads at $0.20.
        $this->assertEqualsWithDelta(4 + 2 + 0.4, Models::cost('claude-opus-5-5', 1_000_000, 100_000, 2_000_000), 1e-9);
        $this->assertEqualsWithDelta(0.10, Models::cost('claude-haiku-5-5', 1_000_000, 0), 1e-9);
        $this->assertSame(Models::cost(Models::DEFAULT, 10, 10), Models::cost('unknown', 10, 10));
    }
}
