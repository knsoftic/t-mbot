<?php

namespace App\Livewire\SystemHealth;

use App\Livewire\Concerns\InteractsWithUi;
use App\Services\AuditLogger;
use App\Services\HealthService;
use App\Services\RecoveryService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('System Health')]
class Index extends Component
{
    use InteractsWithUi;

    public function mount(): void
    {
        $this->authorize(Permissions::SYSTEM_HEALTH);
    }

    public function refresh(): void
    {
        Cache::forget('health:telegram');
    }

    public function recover(RecoveryService $recovery, AuditLogger $audit): void
    {
        $this->authorize(Permissions::CAMPAIGNS_OPERATE);
        $stats = $recovery->recover();
        $audit->log('system.recover', null, 'Manual recovery run', $stats);
        $this->toast("Recovery finished: {$stats['stale_items']} stale item(s), {$stats['requeued']} re-queued, {$stats['uncertain']} marked uncertain, {$stats['rescheduled']} schedule(s) repaired.", 'info');
    }

    public function render(HealthService $health)
    {
        $checks = $health->checks();

        return view('livewire.system-health.index', [
            'checks' => $checks,
            'overall' => collect($checks)->contains('status', 'failed') ? 'failed' : (collect($checks)->contains('status', 'warning') ? 'warning' : 'healthy'),
            'environment' => [
                'PHP' => PHP_VERSION,
                'Laravel' => app()->version(),
                'Environment' => app()->environment(),
                'Debug mode' => config('app.debug') ? 'ON (disable in production)' : 'off',
                'Queue connection' => config('queue.default'),
                'Cache store' => config('cache.default'),
                'Session driver' => config('session.driver'),
                'Server time (UTC)' => now()->utc()->format('Y-m-d H:i:s'),
            ],
        ]);
    }
}
