@php
    use App\Support\Permissions as P;

    $user = auth()->user();
    $pendingOrders = $user->can(P::ORDERS_MANAGE) ? \App\Models\PlanOrder::query()->pending()->count() : 0;

    // [label, route, icon, permission (null = everyone), active patterns, badge]
    $sections = [
        null => [
            ['Dashboard', 'dashboard', 'home', P::DASHBOARD_VIEW, ['dashboard']],
        ],
        'Telegram' => [
            ['Bots', 'bots.index', 'bot', P::BOTS_VIEW, ['bots.*']],
            ['Channels', 'destinations.channels', 'megaphone', P::DESTINATIONS_VIEW, ['destinations.channels']],
            ['Groups', 'destinations.groups', 'user-group', P::DESTINATIONS_VIEW, ['destinations.groups']],
        ],
        'Content' => [
            ['Content Library', 'contents.index', 'photo', P::CONTENTS_VIEW, ['contents.*']],
            ['Categories / Tags', 'tags.index', 'tag', P::CONTENTS_VIEW, ['tags.*']],
        ],
        'Automation' => [
            ['Quick Send', 'quick-send', 'send', P::QUICK_SEND, ['quick-send']],
            ['Campaigns', 'campaigns.index', 'bolt', P::CAMPAIGNS_VIEW, ['campaigns.*']],
            ['Schedules', 'schedules.index', 'calendar', P::CAMPAIGNS_VIEW, ['schedules.*']],
        ],
        'Monitoring' => [
            ['Posting Logs', 'logs.index', 'list', P::LOGS_VIEW, ['logs.*']],
            ['Failed Jobs', 'failed-jobs.index', 'warning', P::FAILED_JOBS_MANAGE, ['failed-jobs.*']],
            ['Audit Logs', 'audit-logs.index', 'shield', P::AUDIT_VIEW, ['audit-logs.*']],
        ],
        'Account' => $user->isCustomer() ? [
            ['Billing & plans', 'billing.index', 'credit-card', null, ['billing.*']],
        ] : [],
        'Sales' => [
            ['Purchase requests', 'admin.orders.index', 'inbox', P::ORDERS_MANAGE, ['admin.orders.*'], $pendingOrders],
            ['Plans', 'admin.plans.index', 'credit-card', P::PLANS_MANAGE, ['admin.plans.*']],
            ['Payment methods', 'admin.payment-methods.index', 'banknotes', P::PLANS_MANAGE, ['admin.payment-methods.*']],
        ],
        'Users' => [
            ['Users', 'users.index', 'users', P::USERS_MANAGE, ['users.*']],
            ['Roles', 'roles.index', 'key', P::ROLES_MANAGE, ['roles.*']],
        ],
        'System' => [
            ['Settings', 'settings.index', 'cog', P::SETTINGS_MANAGE, ['settings.*']],
            ['System Health', 'system-health', 'heart', P::SYSTEM_HEALTH, ['system-health']],
        ],
        'Help' => [
            ['User Guide', 'help.guide', 'info', null, ['help.guide']],
            ['API Docs', 'help.api', 'document', null, ['help.api']],
        ],
    ];
@endphp

@foreach ($sections as $heading => $links)
    {{-- A null permission means the link is open to every signed-in user. --}}
    @php $visible = array_filter($links, fn ($l) => $l[3] === null || $user->can($l[3])); @endphp
    @if ($visible)
        <div>
            @if ($heading)
                <div class="mb-1.5 px-3 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{{ $heading }}</div>
            @endif
            <div class="space-y-0.5">
                @foreach ($visible as $link)
                    @php [$label, $route, $icon, $permission, $patterns] = $link; $badge = $link[5] ?? 0; @endphp
                    <a href="{{ route($route) }}" wire:navigate
                       @class(['nav-link', 'nav-link-active' => request()->routeIs(...$patterns)])>
                        <x-icon :name="$icon" class="size-5" />
                        <span>{{ $label }}</span>
                        @if ($badge > 0)
                            <span class="ml-auto rounded-full bg-amber-500 px-1.5 text-[11px] font-semibold tabular-nums text-white">{{ $badge }}</span>
                        @endif
                    </a>
                @endforeach
            </div>
        </div>
    @endif
@endforeach
