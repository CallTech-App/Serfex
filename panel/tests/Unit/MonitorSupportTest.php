<?php

namespace Tests\Unit;

use App\Services\Monitor\Health;
use App\Services\Monitor\IpBlocks;
use App\Services\Monitor\MonitorException;
use App\Support\Load;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MonitorSupportTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function addresses(): array
    {
        return [
            'address' => [' 45.155.205.233 ', '45.155.205.233'],
            'prefix is masked' => ['198.51.100.77/24', '198.51.100.0/24'],
            'full length prefix' => ['192.0.2.1/32', '192.0.2.1'],
            'widest v4' => ['10.20.30.40/16', '10.20.0.0/16'],
            'v6 is shortened' => ['2001:DB8:0:0::1', '2001:db8::1'],
            'widest v6' => ['2001:db8:1:2::/48', '2001:db8:1::/48'],
        ];
    }

    #[DataProvider('addresses')]
    public function test_normalize(string $in, string $out): void
    {
        $this->assertSame($out, IpBlocks::normalize($in));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refused(): array
    {
        return [
            'name' => ['example.com'],
            'too wide v4' => ['10.0.0.0/8'],
            'too wide v6' => ['2001:db8::/32'],
            'bad prefix' => ['192.0.2.0/33'],
            'loopback' => ['127.0.0.1'],
            'loopback v6' => ['::1'],
            'any' => ['0.0.0.0'],
        ];
    }

    #[DataProvider('refused')]
    public function test_normalize_refuses(string $in): void
    {
        $this->expectException(MonitorException::class);
        IpBlocks::normalize($in);
    }

    public function test_contains(): void
    {
        $this->assertTrue(IpBlocks::contains('198.51.100.0/24', '198.51.100.7'));
        $this->assertFalse(IpBlocks::contains('198.51.100.0/24', '198.51.101.7'));
        $this->assertTrue(IpBlocks::contains('192.0.2.1', '192.0.2.1'));
        $this->assertTrue(IpBlocks::contains('2001:db8:1::/48', '2001:db8:1:ffff::9'));
        $this->assertFalse(IpBlocks::contains('2001:db8:1::/48', '192.0.2.1'));
        $this->assertFalse(IpBlocks::contains('not-an-ip', '192.0.2.1'));
    }

    public function test_health_shows_the_worst_problem_and_the_limit(): void
    {
        $quiet = ['cpu' => 3, 'memory' => 1 << 20, 'php' => ['max_reached' => false, 'queue' => 0], 'server_errors' => 0, 'db' => ['longest' => 0], 'mail_per_hour' => 0];
        [$badges, $rank] = Health::site($quiet, 0, false);
        $this->assertSame(['Normal'], array_map(fn ($b) => $b->label(), $badges));
        $this->assertSame(9, $rank);

        [$badges, $rank] = Health::site([...$quiet, 'cpu' => 120, 'php' => ['max_reached' => true, 'queue' => 4]], 2, true);
        $this->assertSame(['Saldırı', 'Sınırlı'], array_map(fn ($b) => $b->label(), $badges));
        $this->assertSame(['crit', 'info'], array_map(fn ($b) => $b->tone(), $badges));
        $this->assertSame(0, $rank);

        [$badges, $rank] = Health::site([...$quiet, 'db' => ['longest' => 45]], 0, false);
        $this->assertSame(['Yavaş sorgu'], array_map(fn ($b) => $b->label(), $badges));
        $this->assertSame(2, $rank);
    }

    public function test_numbers_are_written_the_turkish_way(): void
    {
        $this->assertSame('%97', Load::cpu(97.4));
        $this->assertSame('%3,2', Load::cpu(3.21));
        $this->assertSame('%0', Load::cpu(0));
        $this->assertSame('1.318', Load::rate(1318.2));
        $this->assertSame('0,4', Load::rate(0.42));
        $this->assertSame('—', Load::io(10));
        $this->assertSame('1 gün 12 sa', Load::uptime(36 * 3600 + 59));
        $this->assertSame('5 sa 3 dk', Load::uptime(5 * 3600 + 180));
    }
}
