<?php

namespace App\Services;

use App\Services\Telegram\TelegramService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Checks for the System Health page. Each check returns
 * ['key', 'label', 'status' => healthy|warning|failed, 'message'].
 */
class HealthService
{
    public function __construct(private readonly SettingsService $settings) {}

    /**
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function checks(): array
    {
        return [
            $this->database(),
            $this->redis(),
            $this->queue(),
            $this->scheduler(),
            $this->telegram(),
            $this->storage(),
        ];
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    public function database(): array
    {
        try {
            $started = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $started) * 1000);

            return $this->result('database', 'Database', 'healthy', DB::connection()->getDriverName()." connection OK ({$ms} ms)");
        } catch (Throwable $e) {
            return $this->result('database', 'Database', 'failed', 'Cannot connect: '.Str::limit($e->getMessage(), 150));
        }
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    public function redis(): array
    {
        $usesRedis = in_array('redis', [config('queue.default'), config('cache.default'), config('session.driver')], true);

        try {
            $pong = Redis::connection()->ping();
            $ok = $pong === true || $pong === 'PONG' || $pong === '+PONG' || (is_object($pong) && method_exists($pong, 'getPayload') && $pong->getPayload() === 'PONG');

            return $this->result('redis', 'Redis', $ok ? 'healthy' : 'failed', $ok ? 'PING → PONG' : 'Unexpected PING reply');
        } catch (Throwable $e) {
            return $usesRedis
                ? $this->result('redis', 'Redis', 'failed', 'Cannot connect: '.Str::limit($e->getMessage(), 150))
                : $this->result('redis', 'Redis', 'warning', 'Not reachable, and not used by the current configuration (queue: '.config('queue.default').', cache: '.config('cache.default').'). Redis is recommended in production.');
        }
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    public function queue(): array
    {
        $queues = array_values(config('telegram.queues'));
        $stale = [];
        $sizes = [];

        foreach ($queues as $queue) {
            $beat = (int) Cache::get('health:queue:'.$queue, 0);

            if ($beat === 0 || time() - $beat > 180) {
                $stale[] = $queue;
            }

            try {
                $sizes[] = "{$queue}: ".Queue::size($queue);
            } catch (Throwable) {
                $sizes[] = "{$queue}: ?";
            }
        }

        $failed = $this->failedJobCount();
        $details = 'Pending — '.implode(', ', $sizes).". Failed jobs: {$failed}.";

        if ($stale !== []) {
            return $this->result('queue', 'Queue workers', 'failed', 'No worker has processed the '.implode(', ', $stale).' queue in the last 3 minutes. Is Supervisor running? '.$details);
        }

        return $this->result('queue', 'Queue workers', $failed > 0 ? 'warning' : 'healthy', 'Workers are processing jobs. '.$details);
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    public function scheduler(): array
    {
        $beat = (int) Cache::get('health:scheduler', 0);

        if ($beat === 0) {
            return $this->result('scheduler', 'Scheduler', 'failed', 'The scheduler has never run. Add the cron entry: * * * * * php artisan schedule:run');
        }

        $age = time() - $beat;

        return $age <= 120
            ? $this->result('scheduler', 'Scheduler', 'healthy', "Last run {$age}s ago.")
            : $this->result('scheduler', 'Scheduler', 'failed', 'Last run '.gmdate('H:i:s', $age).' ago. Check the cron entry.');
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    public function telegram(): array
    {
        return Cache::remember('health:telegram', 60, function () {
            try {
                $started = microtime(true);
                $response = Http::timeout(8)->connectTimeout(5)->get(config('telegram.api_url'));
                $ms = (int) round((microtime(true) - $started) * 1000);

                return $response->status() < 500
                    ? $this->result('telegram', 'Telegram API', 'healthy', "Reachable ({$ms} ms)")
                    : $this->result('telegram', 'Telegram API', 'failed', "Telegram returned HTTP {$response->status()}");
            } catch (Throwable $e) {
                return $this->result('telegram', 'Telegram API', 'failed', 'Not reachable: '.Str::limit(TelegramService::redact($e->getMessage()), 150));
            }
        });
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    public function storage(): array
    {
        $disk = $this->settings->mediaDisk();
        $path = 'health/'.Str::uuid().'.txt';

        try {
            Storage::disk($disk)->put($path, 'ok');
            $ok = Storage::disk($disk)->get($path) === 'ok';
            Storage::disk($disk)->delete($path);

            return $this->result('storage', 'Storage', $ok ? 'healthy' : 'failed', "Disk \"{$disk}\" is ".($ok ? 'writable' : 'not readable'));
        } catch (Throwable $e) {
            return $this->result('storage', 'Storage', 'failed', "Disk \"{$disk}\": ".Str::limit($e->getMessage(), 150));
        }
    }

    public function failedJobCount(): int
    {
        try {
            return DB::table(config('queue.failed.table', 'failed_jobs'))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    /**
     * @return array{key: string, label: string, status: string, message: string}
     */
    private function result(string $key, string $label, string $status, string $message): array
    {
        return compact('key', 'label', 'status', 'message');
    }
}
