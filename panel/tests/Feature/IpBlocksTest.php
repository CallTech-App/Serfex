<?php

namespace Tests\Feature;

use App\Models\IpAllow;
use App\Models\IpBlock;
use App\Models\User;
use App\Services\Agent\AgentClient;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeAgent;
use Tests\TestCase;

/**
 * Güvenlik > IP engelleri: blocking and unblocking in bulk, the allowed
 * list and the history, below the page.
 */
class IpBlocksTest extends TestCase
{
    use RefreshDatabase;

    private FakeAgent $agent;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['name' => 'Sercan Akın']);
        $this->actingAs($this->admin);
        $this->agent = new FakeAgent;
        $this->app->instance(AgentClient::class, $this->agent);
    }

    public function test_history_holds_blocks_that_ran_out_or_were_lifted_and_says_how_they_ended(): void
    {
        $active = IpBlock::create(['address' => '192.0.2.1', 'source' => 'panel', 'expires_at' => now()->addHour()]);
        $forever = IpBlock::create(['address' => '192.0.2.2', 'source' => 'panel']);
        $ranOut = IpBlock::create(['address' => '192.0.2.3', 'source' => 'ai', 'expires_at' => now()->subMinute()]);
        $lifted = IpBlock::create(['address' => '192.0.2.4', 'source' => 'panel', 'removed_at' => now(), 'ended' => 'user', 'removed_by' => $this->admin->id]);
        $allowed = IpBlock::create(['address' => '192.0.2.5', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now(), 'ended' => 'allowed']);
        $replaced = IpBlock::create(['address' => '192.0.2.6', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now(), 'ended' => 'replaced']);
        $byFail2ban = IpBlock::create(['address' => '192.0.2.7', 'source' => 'fail2ban', 'kind' => 'sshd', 'removed_at' => now(), 'ended' => 'sync']);
        // A block lifted before this version has no "ended".
        $old = IpBlock::create(['address' => '192.0.2.8', 'source' => 'panel', 'removed_at' => now()->subDay()]);

        $this->assertEqualsCanonicalizing(
            [$ranOut->id, $lifted->id, $allowed->id, $replaced->id, $byFail2ban->id, $old->id],
            IpBlock::ended()->pluck('id')->all(),
        );
        $this->assertSame('Süresi doldu', $ranOut->endedLabel());
        $this->assertSame('Sercan Akın kaldırdı', $lifted->endedLabel());
        $this->assertSame('İzinlilere eklendi', $allowed->endedLabel());
        $this->assertSame('Süresi değiştirildi', $replaced->endedLabel());
        $this->assertSame('Fail2ban kaldırdı', $byFail2ban->endedLabel());
        $this->assertSame('Kaldırıldı', $old->endedLabel());
        $this->assertTrue($ranOut->endedAt()->equalTo($ranOut->expires_at));
        $this->assertTrue($lifted->endedAt()->equalTo($lifted->removed_at));

        $this->assertSame(60, $active->minutes());
        $this->assertNull($forever->minutes());
    }

    public function test_an_address_is_on_the_allowed_list_once(): void
    {
        $allow = IpAllow::create(['address' => '88.230.14.52', 'note' => 'Ofis', 'user_id' => $this->admin->id, 'country' => 'TR']);
        $this->assertSame('Sercan Akın', $allow->user->name);
        $this->expectException(UniqueConstraintViolationException::class);
        IpAllow::create(['address' => '88.230.14.52']);
    }
}
