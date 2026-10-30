<?php

namespace App\Services\Queue;

use App\Exceptions\JobInterrupted;
use App\Support\Locales;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Queue\Jobs\DatabaseJobRecord;
use Illuminate\Support\Facades\DB;
use stdClass;
use Throwable;

/**
 * Tells a queued job that is running from one whose worker is gone.
 *
 * A worker holds a PostgreSQL advisory lock named after the job while it
 * runs it, and PostgreSQL lets the lock go when the worker's connection
 * closes. A job still marked as taken whose lock is free was left behind
 * by a worker that stopped in the middle of it: a panel update, a restart
 * of the queue, a crash. Such a job is failed at once, so that its
 * failed() records what happened (a site deletion shows "Silme yarıda
 * kaldı" and can be started again), instead of the queue waiting out
 * retry_after (12 hours on the backups queue) with the site stuck on
 * Siliniyor.
 */
class RunningJobs
{
    /** The high half of the lock keys ("SFJB"), apart from other locks. */
    private const LOCK = 0x53464A42;

    /** A job taken this recently may not hold its lock yet. */
    private const GRACE_SECONDS = 60;

    /**
     * Called as a worker starts a job.
     */
    public function started(Job $job): void
    {
        if ($job instanceof DatabaseJob) {
            DB::select('select pg_advisory_lock(?)', [self::key($job->getJobId())]);
        }
    }

    /**
     * Called as a worker is done with a job, however it ended.
     */
    public function finished(Job $job): void
    {
        if (! $job instanceof DatabaseJob) {
            return;
        }
        try {
            DB::select('select pg_advisory_unlock(?)', [self::key($job->getJobId())]);
        } catch (Throwable) {
            // A connection the job left unusable: the lock goes with it,
            // and the job's id is never used again.
        }
    }

    /**
     * True while a worker runs a job of $queue.
     */
    public function busy(string $queue): bool
    {
        $taken = DB::table($this->table())->where('queue', $queue)->whereNotNull('reserved_at')->get(['id', 'reserved_at']);
        foreach ($taken as $row) {
            if ($row->reserved_at >= now()->getTimestamp() - self::GRACE_SECONDS || $this->held((int) $row->id)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fails the jobs whose worker stopped while running them; returns how
     * many.
     */
    public function recover(): int
    {
        $count = 0;
        $taken = DB::table($this->table())->whereNotNull('reserved_at')
            ->where('reserved_at', '<', now()->getTimestamp() - self::GRACE_SECONDS)->get();
        foreach ($taken as $row) {
            $key = self::key((int) $row->id);
            if (! DB::selectOne('select pg_try_advisory_lock(?) as ok', [$key])->ok) {
                continue;
            }
            try {
                // Still the same taking: a worker may have taken it again
                // once retry_after ran out.
                $fresh = DB::table($this->table())->where('id', $row->id)->first();
                if ($fresh !== null && $fresh->reserved_at === $row->reserved_at) {
                    $this->fail($fresh);
                    $count++;
                }
            } finally {
                DB::select('select pg_advisory_unlock(?)', [$key]);
            }
        }

        return $count;
    }

    /**
     * Fails a job the way a worker would: its failed() runs and it is
     * listed with the failed jobs (php artisan queue:failed).
     */
    private function fail(stdClass $row): void
    {
        $connection = $this->connectionFor($row->queue);
        // In the language of the person who started it, as when it ran.
        Locales::apply(json_decode($row->payload, true)['locale'] ?? Locales::serverDefault());
        $e = new JobInterrupted;
        $queue = new DatabaseQueue(DB::connection(), $this->table(), $row->queue);
        $queue->setContainer(app());
        $queue->setConnectionName($connection);
        try {
            (new DatabaseJob(app(), $queue, new DatabaseJobRecord($row), $connection, $row->queue))->fail($e);
        } catch (ModelNotFoundException) {
            // What the job worked on is gone (a deletion that got that far);
            // there is nothing left to mark.
        } catch (Throwable $t) {
            // The job's failed() broke; the job is off the queue all the same.
            report($t);
        }
        app('queue.failer')->log($connection, $row->queue, $row->payload, $e);
    }

    /**
     * Whether some connection holds the lock of job $id.
     */
    private function held(int $id): bool
    {
        $key = self::key($id);

        return DB::table('pg_locks')->where('locktype', 'advisory')->where('granted', true)->where('objsubid', 1)
            ->whereRaw('classid::bigint = ? and objid::bigint = ?', [$key >> 32, $key & 0xFFFFFFFF])->exists();
    }

    /**
     * The advisory lock a worker holds while it runs job $id.
     */
    public static function key(int|string $id): int
    {
        return (self::LOCK << 32) | ((int) $id & 0xFFFFFFFF);
    }

    /**
     * The queue connection whose worker takes jobs of $queue.
     */
    private function connectionFor(string $queue): string
    {
        foreach ((array) config('queue.connections') as $name => $c) {
            if (($c['driver'] ?? null) === 'database' && ($c['queue'] ?? null) === $queue) {
                return $name;
            }
        }

        return 'database';
    }

    private function table(): string
    {
        return (string) config('queue.connections.database.table', 'jobs');
    }
}
