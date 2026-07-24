<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

final class QueueDoctor extends Command
{
    protected $signature = 'maxguard:queue-doctor';

    protected $description = 'Check the queue connection and print the worker command required by MaxGuard';

    public function handle(): int
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", '');
        $controlQueue = (string) config('maxguard.queue', 'scans');
        $pageQueue = (string) config('maxguard.page_queue', 'scan-pages');
        $finalizeQueue = (string) config('maxguard.finalize_queue', 'scan-finalize');
        $controlTimeout = max(
            120,
            (int) config('maxguard.orchestrator_timeout_seconds', 900),
            (int) config('maxguard.finalize_timeout_seconds', 900),
        );
        $pageTimeout = max(120, (int) config('maxguard.page_job_timeout_seconds', 1800));
        $timeout = max($controlTimeout, $pageTimeout);
        $memory = max(128, (int) config('maxguard.worker_memory_mb', 1024));
        $pageWorkers = max(1, (int) config('maxguard.recommended_page_workers', 6));
        $cacheStore = (string) config('cache.default', 'file');
        $controlWorker = "php artisan queue:work {$connection} --queue={$controlQueue},{$finalizeQueue} --sleep=2 --tries=3 --timeout={$controlTimeout} --memory={$memory}";
        $pageWorker = "php artisan queue:work {$connection} --queue={$pageQueue} --sleep=1 --tries=2 --timeout={$pageTimeout} --memory={$memory}";

        $this->table(['Setting', 'Value'], [
            ['Queue connection', $connection],
            ['Queue driver', $driver !== '' ? $driver : 'not configured'],
            ['Shared rate-limit cache', $cacheStore],
            ['Control queues', $controlQueue.', '.$finalizeQueue],
            ['Parallel page queue', $pageQueue],
            ['Page batch size', max(1, min(100, (int) config('maxguard.page_batch_size', 10))).' URLs'],
            ['Recommended page workers', $pageWorkers],
            ['Maximum job timeout', $timeout.' seconds'],
            ['Worker memory limit', $memory.' MB'],
        ]);

        if ($driver === '') {
            $this->error("Queue connection [{$connection}] is not configured in config/queue.php.");

            return self::FAILURE;
        }

        if ($driver === 'sync') {
            $this->warn('The sync driver runs the crawler inside the HTTP request and can time out. Use QUEUE_CONNECTION=database or redis in production.');
            $this->info('No worker is required while QUEUE_CONNECTION=sync.');

            return self::SUCCESS;
        }

        if ($cacheStore === 'array') {
            $this->error('CACHE_DRIVER=array cannot share per-host rate-limit locks between queue workers. Use file on one server, or database/Redis across servers.');

            return self::FAILURE;
        }

        if ($driver === 'database' && ! $this->checkDatabaseQueue($connection, [$controlQueue, $pageQueue, $finalizeQueue])) {
            return self::FAILURE;
        }

        if ($driver === 'redis' && ! $this->checkRedisQueue($connection)) {
            return self::FAILURE;
        }

        if (! $this->checkRetryAfter($connection, $timeout)) {
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Queue backend is reachable. Keep one control worker running:');
        $this->line($controlWorker);
        $this->newLine();
        $this->info("Run {$pageWorkers} page worker processes with this same command (Supervisor numprocs={$pageWorkers}):");
        $this->line($pageWorker);
        $this->newLine();
        $this->comment('This command validates the backend but cannot prove that a worker process is currently running.');

        return self::SUCCESS;
    }

    /** @param list<string> $queues */
    private function checkDatabaseQueue(string $connection, array $queues): bool
    {
        $databaseConnection = config("queue.connections.{$connection}.connection");
        $table = (string) config("queue.connections.{$connection}.table", 'jobs');

        try {
            $database = DB::connection(is_string($databaseConnection) ? $databaseConnection : null);
            if (! $database->getSchemaBuilder()->hasTable($table)) {
                $this->error("Queue table [{$table}] does not exist.");
                $this->line('Run [php artisan migrate] first. If the project has no jobs migration, run [php artisan queue:table] and [php artisan migrate].');

                return false;
            }

            $waiting = $database->table($table)->whereIn('queue', $queues)->count();
            $this->info("Database queue table [{$table}] is available; {$waiting} MaxGuard job(s) are waiting across ".implode(', ', $queues).'.');

            return true;
        } catch (Throwable $exception) {
            $this->error('Database queue check failed: '.$exception->getMessage());

            return false;
        }
    }

    private function checkRedisQueue(string $connection): bool
    {
        $redisConnection = (string) config("queue.connections.{$connection}.connection", 'default');

        try {
            app('redis')->connection($redisConnection)->command('ping');
            $this->info("Redis connection [{$redisConnection}] is reachable.");

            return true;
        } catch (Throwable $exception) {
            $this->error('Redis queue check failed: '.$exception->getMessage());

            return false;
        }
    }

    private function checkRetryAfter(string $connection, int $timeout): bool
    {
        $retryAfter = config("queue.connections.{$connection}.retry_after");
        if (! is_numeric($retryAfter)) {
            return true;
        }

        $retryAfter = (int) $retryAfter;
        if ($retryAfter > $timeout) {
            $this->info("Queue retry_after [{$retryAfter}s] is greater than the scan timeout [{$timeout}s].");

            return true;
        }

        $recommended = $timeout + 300;
        $this->error("Queue retry_after [{$retryAfter}s] must be greater than the MaxGuard timeout [{$timeout}s] to prevent duplicate scans.");
        $this->line("Set retry_after to at least {$recommended} seconds in config/queue.php, then run php artisan optimize:clear.");

        return false;
    }
}
