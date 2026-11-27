<?php

namespace Workbench\Database\Seeders;

use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Keepsuit\Spyglass\Modules\Logs\LogsModule;

/**
 * Demo data for every module, relative to now: run `composer build` again to refresh it.
 */
class DatabaseSeeder extends Seeder
{
    protected CarbonInterface $now;

    public function run(): void
    {
        $this->now = now()->utc();

        $this->schedule();
        $this->queue();
        $this->exceptions();
        $this->logs();
    }

    protected function schedule(): void
    {
        $tasks = [
            // label, expression, status of the last run, duration
            ['backup:run', '0 2 * * *', 'success', 184_000],
            ['reports:send-daily', '0 7 * * *', 'failed', 4_210],
            ['cache:prune-stale-tags', '0 * * * *', 'success', 812],
            ['routes/console.php:14', '*/5 * * * *', 'running', null],
            ['sitemap:generate', '30 3 * * *', 'interrupted', null],
        ];

        foreach ($tasks as [$label, $expression, $status, $duration]) {
            DB::table('spyglass_schedule_tasks')->insert([
                'key' => sha1($label),
                'label' => $label,
                'command' => str_contains($label, '/') ? null : "php artisan {$label}",
                'expression' => $expression,
                'timezone' => 'Europe/Rome',
                'first_seen_at' => $this->at($this->now->copy()->subDays(20)),
                'last_seen_at' => $this->at($this->now),
            ]);

            for ($i = 0; $i < 12; $i++) {
                $started = $this->now->copy()->subHours($i * 6 + 1);
                $runStatus = $i === 0 ? $status : ($i % 5 === 3 ? 'failed' : 'success');
                $open = in_array($runStatus, ['running', 'interrupted'], true);

                DB::table('spyglass_schedule_runs')->insert([
                    'id' => (string) Str::ulid(),
                    'task' => sha1($label),
                    'started_at' => $this->at($started),
                    'finished_at' => $open ? null : $this->at($started->copy()->addMilliseconds($duration ?? 900)),
                    'duration_ms' => $open ? null : ($duration ?? 900),
                    'status' => $runStatus,
                    'exit_code' => match ($runStatus) {
                        'failed' => 1,
                        'success' => 0,
                        default => null,
                    },
                    'host' => 'web-'.($i % 2 + 1),
                    'output' => $runStatus === 'failed' ? "Sending report to 42 recipients\nConnection refused by the mail server" : null,
                    'exception_class' => $runStatus === 'failed' ? 'Symfony\Component\Mailer\Exception\TransportException' : null,
                    'fingerprint' => $runStatus === 'failed' ? sha1('mailer') : null,
                ]);
            }
        }
    }

    protected function queue(): void
    {
        $jobs = [['default', 'App\Jobs\SyncCatalog', 6], ['emails', 'App\Mail\InvoiceIssued', 14], ['default', 'App\Jobs\GenerateThumbnails', 3]];
        $rows = [];

        // Five-minute buckets over the last day, busier during the day.
        for ($b = 0; $b < 288; $b++) {
            $bucket = $this->now->copy()->subMinutes($b * 5);
            $bucket->setTimestamp($bucket->getTimestamp() - $bucket->getTimestamp() % 300);

            foreach ($jobs as [$queue, $job, $rate]) {
                if (($b * 7 + strlen($job)) % 11 === 0) {
                    continue;
                }

                $processed = (int) round($rate * (0.4 + 0.6 * sin(((int) $bucket->format('G') - 6) / 24 * 2 * M_PI) ** 2) + $b * 13 % 5);
                $failed = ($b * 31 + $rate) % 23 === 0 ? 1 + $b % 3 : 0;

                $rows[] = [
                    'bucket' => $bucket->format('Y-m-d H:i:s'), 'connection' => 'database', 'queue' => $queue, 'job' => $job,
                    'queued' => $processed + $failed, 'processed' => $processed, 'failed' => $failed,
                    'released' => $b % 17 === 0 ? 1 : 0, 'timed_out' => $b % 61 === 0 ? 1 : 0,
                    'duration_sum' => $processed * 340, 'duration_max' => 200 + $b * 97 % 4000,
                ];
            }
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('spyglass_queue_aggregates')->insert($chunk);
        }

        $failures = [
            ['App\Jobs\SyncCatalog', 'default', 'Illuminate\Http\Client\ConnectionException', 'catalog'],
            ['App\Mail\InvoiceIssued', 'emails', 'Symfony\Component\Mailer\Exception\TransportException', 'mailer'],
            ['App\Jobs\GenerateThumbnails', 'default', 'RuntimeException', 'thumbnails'],
        ];

        foreach ($failures as $i => [$job, $queue, $exception, $fingerprint]) {
            for ($k = 0; $k < 2 + $i; $k++) {
                $uuid = (string) Str::uuid();
                $at = $this->now->copy()->subMinutes(17 + $i * 95 + $k * 41)->format('Y-m-d H:i:s');
                $tenant = $k % 2 ? 'north' : 'south';

                // Retry and delete go through the failer, so the job must be there too.
                DB::table('failed_jobs')->insert([
                    'uuid' => $uuid, 'connection' => 'database', 'queue' => $queue, 'failed_at' => $at,
                    'payload' => json_encode(['uuid' => $uuid, 'displayName' => $job, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'maxTries' => 3, 'data' => ['commandName' => $job]]),
                    'exception' => "{$exception}: The remote host did not answer in time.\n#0 app/Jobs/SyncCatalog.php(41): handle()",
                ]);
                DB::table('spyglass_queue_failed_jobs')->insert([
                    'uuid' => $uuid, 'connection' => 'database', 'queue' => $queue, 'job' => $job, 'attempts' => 3,
                    'exception_class' => $exception, 'fingerprint' => sha1($fingerprint), 'failed_at' => $at,
                    'scope' => "tenant:{$tenant}", 'context' => json_encode(['tenant_id' => $tenant]),
                ]);
            }
        }
    }

    protected function exceptions(): void
    {
        $groups = [
            ['mailer', 'Symfony\Component\Mailer\Exception\TransportException', 'Connection could not be established with host "smtp.example.test:587"', 'app/Reports/DailyReport.php', 58, 37, false],
            ['catalog', 'Illuminate\Http\Client\ConnectionException', 'cURL error 28: Operation timed out after 10001 milliseconds', 'app/Jobs/SyncCatalog.php', 41, 212, false],
            ['thumbnails', 'RuntimeException', 'Unsupported image type image/heic', 'app/Jobs/GenerateThumbnails.php', 77, 9, true],
            ['checkout', 'Illuminate\Database\QueryException', 'SQLSTATE[23000]: insert into `orders` (`number`, `customer_id`) values (?, ?)', 'app/Http/Controllers/CheckoutController.php', 112, 4, false],
        ];

        foreach ($groups as [$key, $class, $message, $file, $line, $count, $resolved]) {
            $fingerprint = sha1($key);
            $last = $this->now->copy()->subMinutes($count % 50 + 3);

            DB::table('spyglass_groups')->insert([
                'kind' => 'exception', 'signature' => $fingerprint, 'class' => $class, 'message' => $message, 'file' => $file, 'line' => $line,
                'count' => $count, 'first_seen_at' => $this->at($this->now->copy()->subDays(6)), 'last_seen_at' => $this->at($last),
                'resolved_at' => $resolved ? $this->at($last->copy()->addMinute()) : null,
            ]);

            foreach ([['job', 'App\Jobs\SyncCatalog', 0.7], ['request', 'POST /checkout', 0.3]] as [$type, $source, $share]) {
                DB::table('spyglass_groups')->insert([
                    'kind' => 'exception', 'signature' => $fingerprint, 'source_type' => $type, 'source_hash' => sha1($source), 'source' => $source,
                    'count' => (int) round($count * $share), 'last_seen_at' => $this->at($last),
                ]);
            }

            for ($o = 0; $o < min(6, $count); $o++) {
                $tenant = $o % 2 ? 'north' : 'south';

                DB::table('spyglass_entries')->insert([
                    'kind' => 'exception', 'signature' => $fingerprint, 'recorded_at' => $this->at($last->copy()->subMinutes($o * 37)), 'message' => $message,
                    'body' => json_encode([['file' => $file, 'line' => $line, 'function' => 'handle'], ['file' => 'vendor/laravel/framework/src/Illuminate/Container/BoundMethod.php', 'line' => 36, 'function' => '{closure}']]),
                    'source_type' => $o % 3 ? 'job' : 'request', 'source' => $o % 3 ? 'App\Jobs\SyncCatalog' : 'POST /checkout',
                    'user_id' => $o % 3 ? null : '1042', 'scope' => "tenant:{$tenant}", 'context' => json_encode(['tenant_id' => $tenant]),
                ]);
            }
        }
    }

    protected function logs(): void
    {
        $messages = [
            ['warning', 'Payment webhook signature mismatch', 'POST /webhooks/payments'],
            ['error', 'Retrying export after rate limit', 'App\Jobs\SyncCatalog'],
            ['critical', 'Stock level below threshold for SKU {sku}', 'App\Jobs\SyncCatalog'],
            ['warning', 'Slow query detected (2.4 s) on orders index', 'GET /orders'],
            ['notice', 'Disk usage above 85% on /var/www/storage', 'schedule:run'],
        ];

        foreach ($messages as $m => [$level, $message, $source]) {
            $signature = LogsModule::signature($level, $message);
            $type = str_contains($source, ' ') ? 'request' : (str_contains($source, ':') ? 'command' : 'job');
            $count = 0;

            for ($i = $m; $i < 60; $i += count($messages)) {
                $count++;

                DB::table('spyglass_entries')->insert([
                    'kind' => 'log', 'signature' => $signature, 'level' => $level, 'message' => $message,
                    'recorded_at' => $this->at($this->now->copy()->subMinutes($i * 23 + 2)),
                    'body' => json_encode(['sku' => 'TS-'.(1000 + $i), 'attempt' => $i % 3 + 1]),
                    'source_type' => $type, 'source' => $source,
                    'scope' => $i % 3 ? 'tenant:north' : null, 'context' => $i % 3 ? json_encode(['tenant_id' => 'north']) : null,
                ]);
            }

            DB::table('spyglass_groups')->insert([
                'kind' => 'log', 'signature' => $signature, 'source_type' => $type, 'source_hash' => sha1($source), 'source' => $source,
                'count' => $count, 'last_seen_at' => $this->at($this->now->copy()->subMinutes($m * 23 + 2)),
            ]);
        }
    }

    protected function at(CarbonInterface $time): string
    {
        return $time->format('Y-m-d H:i:s.v');
    }
}
