<?php

namespace Tests\Concerns;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Real concurrency against MySQL/MariaDB: separate PHP processes (tests/Concurrency/worker.php)
 * wait for one shared instant and then run the production code path at the same time, each on
 * its own connection, with real commits.
 *
 * Every race records evidence (workers, outcomes, slowest worker, InnoDB row-lock waits and
 * deadlocks during the race). Set CONCURRENCY_EVIDENCE=<file> to append it as JSON lines.
 */
trait RunsRaces
{
    /**
     * @param  list<array{0: string, 1: array}>  $workers  [scenario, arguments]
     * @return list<array>
     */
    protected function race(array $workers): array
    {
        $connection = config('database.connections.'.DB::getDefaultConnection());
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => DB::getDefaultConnection(), 'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => (string) $connection['password'],
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'MAIL_MAILER' => 'array', 'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'BCRYPT_ROUNDS' => '4',
        ];
        $startAt = sprintf('%.3f', microtime(true) + 6 + count($workers) * 0.08);
        $before = $this->lockMetrics();

        $processes = array_map(function (array $worker) use ($env, $startAt) {
            $process = new Process([PHP_BINARY, base_path('tests/Concurrency/worker.php'), $worker[0], $startAt, json_encode($worker[1])], base_path(), $env, null, 300);
            $process->start();

            return $process;
        }, $workers);

        $results = array_map(function (Process $process) {
            try {
                $process->wait();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                // Evidence for a stuck worker: what MySQL was doing (open transactions, lock waits).
                $transactions = collect(DB::select('SELECT trx_state, TIMESTAMPDIFF(SECOND, trx_started, NOW()) AS age_s, trx_query FROM information_schema.innodb_trx'))
                    ->map(fn ($t) => "{$t->trx_state} {$t->age_s}s ".mb_substr((string) $t->trx_query, 0, 120))->implode("\n");
                $this->fail("Worker timed out.\nOpen transactions:\n".($transactions ?: '(none)')."\nWorker output:\n".$process->getOutput().$process->getErrorOutput());
            }
            $line = trim((string) collect(explode("\n", trim($process->getOutput())))->last());
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded, 'Worker output: '.$process->getOutput().$process->getErrorOutput());

            return $decoded;
        }, $processes);

        $this->recordEvidence($workers, $results, $before, $this->lockMetrics());

        return $results;
    }

    protected function statuses(array $results): array
    {
        $counts = array_count_values(array_map(fn ($r) => (string) $r['status'], $results));
        ksort($counts);

        return $counts;
    }

    protected function noExceptions(array $results): void
    {
        $exceptions = array_values(array_filter($results, fn ($r) => $r['status'] === 'exception'));
        $this->assertSame([], $exceptions, 'No worker may fail with an unexpected exception (deadlock, lock wait timeout, 500).');
    }

    protected function shoppers(int $count, $size, int $quantity = 1): array
    {
        return array_map(function () use ($size, $quantity) {
            $user = User::factory()->create();
            $this->putInCart($user, $size, $quantity);

            return $user;
        }, range(1, $count));
    }

    /** InnoDB counters (cumulative, server-wide): row lock waits, their total time, deadlocks. */
    private function lockMetrics(): array
    {
        $status = collect(DB::select("SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits', 'Innodb_row_lock_time')"))->pluck('Value', 'Variable_name');
        $deadlocks = collect(DB::select("SELECT `COUNT` AS c FROM information_schema.INNODB_METRICS WHERE NAME = 'lock_deadlocks'"))->first()?->c;

        return ['lock_waits' => (int) ($status['Innodb_row_lock_waits'] ?? 0), 'lock_time_ms' => (int) ($status['Innodb_row_lock_time'] ?? 0), 'deadlocks' => (int) ($deadlocks ?? 0)];
    }

    private function recordEvidence(array $workers, array $results, array $before, array $after): void
    {
        $file = getenv('CONCURRENCY_EVIDENCE');
        if (!$file) {
            return;
        }

        $durations = array_filter(array_map(fn ($r) => $r['ms'] ?? null, $results), fn ($v) => $v !== null);
        file_put_contents($file, json_encode([
            'test' => $this->name(),
            'workers' => count($workers),
            'outcomes' => $this->statuses($results),
            'slowest_ms' => $durations ? max($durations) : null,
            'median_ms' => $durations ? (function ($d) { sort($d); return $d[intdiv(count($d), 2)]; })(array_values($durations)) : null,
            'lock_waits' => $after['lock_waits'] - $before['lock_waits'],
            'lock_wait_ms' => $after['lock_time_ms'] - $before['lock_time_ms'],
            'deadlocks' => $after['deadlocks'] - $before['deadlocks'],
        ])."\n", FILE_APPEND);
    }
}
