<?php

namespace App\Jobs;

use App\Models\MemberImport;
use App\Models\Scopes\AccountScope;
use App\Services\MemberImportService;
use App\Services\PostingService;
use App\Services\Telegram\TelegramApiException;
use App\Services\Telegram\TelegramErrorType;
use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Works through a member import in short slices so progress shows up live and
 * other jobs keep running; queues itself again until every row is handled.
 */
class ProcessMemberImportJob implements ShouldQueue
{
    use Queueable;

    public int $maxExceptions = 3;

    public int $timeout = 120;

    public function __construct(public int $importId)
    {
        $this->onQueue('default');
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(12);
    }

    public function handle(MemberImportService $imports, PostingService $posting): void
    {
        $import = MemberImport::query()->withoutGlobalScope(AccountScope::class)->find($this->importId);

        if (! $import || ! $import->isRunning()) {
            return;
        }

        // Respect a flood-control pause another job started for this bot.
        $cooldown = (int) Cache::get(PostingService::cooldownKey($import->bot_id), 0) - now()->getTimestamp();

        if ($cooldown > 0) {
            $this->release($cooldown + 1);

            return;
        }

        // One worker per import at a time.
        $lock = Cache::lock("member-import:{$import->id}", $this->timeout);

        if (! $lock->get()) {
            $this->release(10);

            return;
        }

        $more = false;

        try {
            $more = $imports->process($import);
        } catch (TelegramApiException $e) {
            $wait = $e->type === TelegramErrorType::FloodControl ? max(1, (int) ($e->retryAfter ?? 30)) + 1 : 30;

            if ($e->type === TelegramErrorType::FloodControl) {
                $posting->startCooldown($import->bot_id, $wait);
            }

            $this->release($wait);

            return;
        } finally {
            $lock->release();
        }

        if ($more) {
            self::dispatch($import->id);
        }
    }

    public function failed(?Throwable $exception): void
    {
        MemberImport::query()->withoutGlobalScope(AccountScope::class)->whereKey($this->importId)
            ->whereIn('status', [MemberImport::PENDING, MemberImport::PROCESSING])
            ->update(['status' => MemberImport::FAILED, 'error' => 'Processing stopped unexpectedly. Use “Check again” to continue.', 'finished_at' => now()]);
    }
}
