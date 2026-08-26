<?php

namespace Tests;

use Illuminate\Contracts\Queue\ClearableQueue;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Base case for tests that need the database to behave like production.
 *
 * The Feature suite runs under `RefreshDatabase`, which wraps every test in a
 * transaction that is rolled back at the end. That makes it impossible to
 * observe row locks, commit ordering or anything a queue worker would see, so
 * the Concurrency suite truncates instead and drives the real `database` queue.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();

        // phpunit.xml pins QUEUE_CONNECTION=sync so the Feature suite can assert
        // on job side effects inline. Here we want the production connection:
        // Redis does not take part in the database transaction, which is the
        // whole reason dispatches have to be deferred until after commit.
        config(['queue.default' => 'redis']);

        $queue = Queue::connection('redis');

        if ($queue instanceof ClearableQueue) {
            $queue->clear('default');
        }
    }

    protected function queueSize(string $queue = 'default'): int
    {
        return Queue::connection('redis')->size($queue);
    }

    /**
     * Run each task in its own forked process so they contend on the same rows.
     *
     * @param  list<callable(): void>  $tasks
     */
    protected function runConcurrently(array $tasks): void
    {
        if (! function_exists('pcntl_fork') || ! function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl and posix are required to exercise real concurrency.');
        }

        // Forked children would otherwise share the parent's PDO socket and
        // corrupt each other's protocol frames.
        DB::disconnect();

        $startAt = microtime(true) + 0.25;

        $pids = [];

        foreach ($tasks as $task) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                $this->fail('Unable to fork a concurrency worker.');
            }

            if ($pid === 0) {
                $this->runWorker($task, $startAt);
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }

        DB::reconnect();
    }

    /**
     * @param  callable(): void  $task
     */
    private function runWorker(callable $task, float $startAt): never
    {
        try {
            DB::reconnect();

            // Barrier: without it the first worker commits before the last one
            // has even opened a connection, and nothing contends.
            $waitMicroseconds = (int) (($startAt - microtime(true)) * 1_000_000);

            if ($waitMicroseconds > 0) {
                usleep($waitMicroseconds);
            }

            $task();
        } catch (Throwable) {
            // Losing the race is a valid outcome. All assertions run in the
            // parent against committed state.
        }

        // SIGKILL rather than exit(): PHPUnit's shutdown handlers are inherited
        // by the child and would emit a second, bogus test report.
        posix_kill(posix_getpid(), SIGKILL);

        exit(0);
    }
}
