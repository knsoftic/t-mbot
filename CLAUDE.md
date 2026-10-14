# Telegram Automation Platform — notes for AI agents

Laravel 13 + Livewire 4 + Tailwind 4 admin panel and posting engine. See README.md for features, setup and deployment.

## Local environment (this machine)
- XAMPP ships PHP 8.2, which is too old. Use the portable PHP in `.tools/php84/php.exe` (git-ignored), e.g.
  `.tools/php84/php.exe artisan test`, `.tools/php84/php.exe /c/composer/composer.phar install`.
- MySQL/MariaDB from XAMPP, database `telegram_automation`; queue and cache use the `database` driver locally (no Redis).
- Frontend: `npm run build` after changing Blade classes (the Vite dev server served stale CSS here).
- Local test admin credentials: `storage/app/dev-admin-credentials.txt` (git-ignored, local only).

## Where things live
- Bot API calls only in `app/Services/Telegram/TelegramService.php`; content → send* mapping and file_id caching in `TelegramPublisher`.
- Campaign lifecycle and the locked claim step: `CampaignService::process()`; delivery/retry rules: `PostingService`; time math: `ScheduleCalculator` (UTC in/out).
- Never send posts from HTTP requests — dispatch jobs. Never expose `TelegramBot::$token` (hidden + encrypted; API uses `masked_token`).
- Permissions: slugs in `App\Support\Permissions`; Super Admin bypass via `Gate::before`.
- Accounts: bots, destinations, contents, tags, campaigns and posting logs have an owner (`user_id`, trait `BelongsToAccount` + `AccountScope`). Users without `accounts.view-all` are customers: they only see their own rows and are limited by their plan (`PlanLimiter`). Validation `exists` rules must use `Accounts::exists()`. Purchases / approval / expiry: `BillingService`.

## Conventions
- Run `.tools/php84/php.exe vendor/bin/pint` and `.tools/php84/php.exe artisan test` before finishing; tests must not hit the real Telegram API (`tests/Support/FakeTelegram.php`).
- Livewire actions that call services wrap them in `$this->attempt()` (turns ValidationException into field errors + toast).
- `<x-confirm action="method(args)">` for destructive actions.
