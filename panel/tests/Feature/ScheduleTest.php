<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The panel's own schedule (routes/console.php).
 *
 * A task keeps a lock while it runs. Most locks last a day; a few last
 * minutes, so that a run cut short by a reboot does not stop a
 * once-a-minute check for a day. When such a run hangs instead, another
 * starts each time the lock runs out, and every one of them holds
 * database connections until the panel has none left for its pages.
 */
class ScheduleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<Event>
     */
    private function shortLocked(): array
    {
        return array_values(array_filter(app(Schedule::class)->events(), fn (Event $e) => $e->withoutOverlapping && $e->expiresAt < 1440));
    }

    public function test_a_task_whose_lock_runs_out_in_minutes_is_stopped_when_it_hangs(): void
    {
        $events = $this->shortLocked();
        $commands = implode("\n", array_map(fn (Event $e) => $e->command, $events));
        $this->assertStringContainsString('serfex:uptime', $commands);
        $this->assertStringContainsString('serfex:laravel-watch', $commands);

        foreach ($events as $event) {
            // timeout(1) ends the run, and what it started, after N seconds.
            $this->assertSame(1, preg_match('#^/usr/bin/timeout -k \d+ (\d+) #', $event->command, $m), $event->command.' has no time limit');
            // No more than three runs of a task at a time.
            $this->assertLessThanOrEqual(3 * $event->expiresAt * 60, (int) $m[1], $event->command);
            $this->assertGreaterThanOrEqual($event->expiresAt * 60, (int) $m[1], $event->command);
        }
    }

    public function test_a_time_limited_task_still_runs(): void
    {
        $watch = collect($this->shortLocked())->first(fn (Event $e) => str_contains($e->command, 'serfex:laravel-watch'));
        $this->assertNotNull($watch);

        exec($watch->command.' 2>&1', $output, $status);

        $this->assertSame(0, $status, implode("\n", $output));
        $this->assertStringContainsString('0 uygulama okundu.', implode("\n", $output));
    }
}
