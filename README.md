# Telegram Automation Platform

A self-hosted, production-ready **Telegram auto-posting & automation platform** built with Laravel 13, Livewire 4, MySQL and Redis.

Connect multiple Telegram bots, register channels and groups, build a content library (text, images, videos, documents, audio, GIFs), arrange content into sequences and let campaigns publish them one by one on a schedule, through the queue, with retries, flood-control handling, crash recovery and full logging.

```
CONTENT → SEQUENCE → CAMPAIGN → BOT → DESTINATION → TRIGGER/SCHEDULE → QUEUE → TELEGRAM API → POST → LOG → NEXT ITEM
```

---

## Contents

1. [Features](#features)
2. [Architecture](#architecture)
3. [Requirements](#requirements)
4. [Local installation](#local-installation)
5. [Environment variables](#environment-variables)
6. [Telegram setup (bot & channel)](#telegram-setup)
7. [How campaigns run](#how-campaigns-run)
8. [Production deployment on Ubuntu](#production-deployment-on-ubuntu-vps)
9. [REST API](#rest-api)
10. [Testing](#testing)
11. [Troubleshooting](#troubleshooting)
12. [Extending the platform](#extending-the-platform)

---

## Features

| Area | What you get |
|---|---|
| **Bots** | Unlimited bots, token verified with `getMe`, tokens **encrypted at rest** and never shown again, enable/disable, connection status, hourly token check. |
| **Channels & groups** | Channels, groups, supergroups by numeric ID, `@username` or `t.me` link. *Test message* runs `getChat` + `getChatMember` (checks the bot is an admin with **Post messages**) and sends a message. |
| **Content library** | Text, image, video, document, audio, animation/GIF. Media by **HTTPS URL**, **upload** (local or S3) or **Telegram file_id**. HTML / MarkdownV2 formatting, live Telegram-style preview, tags, search, filters, duplicate, activate/deactivate. |
| **Media efficiency** | After the first post, the Telegram `file_id` is cached **per bot** and reused — URLs are not downloaded again and files are not re-uploaded. |
| **Auto Queue** | Set a channel and daily posting times once (days of the week, timezone, optional tag, order). At every time the next active Content Library item is posted; new content joins the line automatically and after the last item a new round starts. Shows the coming posts with their content, the last results, "Post next now" and pause; missed times (server down > 15 min) and used-up plan limits skip the time with a reason. Driven by `auto-queues:dispatch-due` every 10 seconds. |
| **Quick Send** | Save a bot + channel/group pair once (with formatting / silent / no-preview / protected defaults), then type a message and press Send (or Ctrl+Enter). Delivered through the Telegram queue with flood control, shown live (Sending → Sent / Failed), logged, and counted against plan sending limits. |
| **Welcome DMs** | Per channel / group welcome message (placeholders, formatting, link button) sent privately to people who join through a bot-created **join-request link** (with optional auto-approve), and to direct joiners who have started the bot — the only cases Telegram allows. Bots receive updates through a secret-verified webhook (`/api/telegram/webhook/{bot}`, HTTPS required); updates are de-duplicated and processed on the Telegram queue. |
| **Member import** | Upload a CSV / TXT / XLSX list of usernames or user IDs for a group or channel. Each person is checked: already a member, banned, pending join request (approved), started the bot (private invite with the join-request link), or not reachable (approved automatically when they request with the link). Invalid entries, phone numbers and duplicates are skipped with a reason; live progress, per-person report, filters, "Check again" and CSV export. The Bot API cannot add users, and bulk adding through user accounts breaks Telegram's terms, so nobody is added without asking and requests from people who are not listed stay pending. |
| **Landing page** | Public homepage at `/` (features, how it works, pricing, FAQ) for visitors; signed-in users are sent to the dashboard. |
| **Campaigns** | 10-step wizard, drag-and-drop sequence builder, sequential or random order, test mode (posts to a test chat), per-campaign timezone. |
| **Triggers** | Manual (Run Now), Scheduled (exact date/time per item), Interval (1 min … 24 h or custom, e.g. 37 min), Fixed times, Daily, Weekly. |
| **Controls** | Run Now, Pause, Resume (continues from the next item), Stop, Restart (from item #1, history kept), Skip, Retry item. |
| **Reliability** | Queue-only delivery, row locking + atomic state changes + unique keys (an item is never sent twice), automatic retries with exponential backoff, Telegram `retry_after` honoured, crash recovery that never duplicates posts. |
| **Repeat** | Stop, repeat immediately, daily, weekly or indefinitely (optionally N times). |
| **Monitoring** | Dashboard, schedules timeline, posting logs (with Telegram responses), failed jobs (retry/delete), audit log, system health, admin notifications (bell + optional e-mail). |
| **Security** | Roles & permissions, account status, login throttling, password reset, CSRF/XSS protection, encrypted tokens, token redaction in logs, Sanctum API tokens, audit logging. |
| **Accounts & subscriptions** | Public sign-up (`/register`) and pricing page (`/pricing`). Every customer works only with their own bots, channels, content and campaigns (global account scope + account-aware validation). Monthly plans in USD (Free, Plan 1–3 by default, fully editable) limit bots, channels, groups, campaigns, content, posts per day / week / month, the shortest interval, uploads, AI and API access. A used-up sending limit postpones posts until the reset instead of dropping them. Manual payments: admins manage payment methods (address, instructions, QR); customers send the transaction reference (+ optional screenshot); admins verify, contact the buyer by e-mail / phone / WhatsApp and approve or reject. Expiry, renewal (+1 month) and reminders are automatic. |
| **Offline AI drafts** (optional) | "✨ AI se likho" in the content form writes a post/caption draft with a **local model on your own server** ([Ollama](https://ollama.com), e.g. `qwen2.5:1.5b`). English / Urdu / Roman Urdu / Hindi, tone, length, emojis, hashtags. No external AI service, no API key; drafts are never posted automatically. |

---

## Architecture

```
app/
├── Console/Commands/      campaigns:dispatch-due · campaigns:recover · bots:check · app:create-admin
├── Contracts/Publisher    platform-neutral delivery interface (Telegram today)
├── Enums/                 statuses, triggers, repeat modes, failure behaviours …
├── Events/ Listeners/     status changes, failures → admin notifications
├── Http/Controllers/      auth, media streaming, REST API (Api/*)
├── Jobs/                  ProcessCampaignJob · SendTelegram{Message,Photo,Video,Document,Audio,Animation}Job
│                          RetryTelegramPostJob · TestDestinationJob · QueueHeartbeatJob
├── Livewire/              admin panel pages
├── Models/                TelegramBot, TelegramDestination, Content, Campaign, CampaignItem, Schedule, PostingLog …
├── Notifications/         AdminAlert (database + mail)
├── Policies/              UserPolicy
└── Services/
    ├── Telegram/TelegramService     the only class that talks to the Bot API
    ├── Telegram/TelegramPublisher   content → correct send* call, file_id caching
    ├── CampaignService              lifecycle + the locked "claim next item" step
    ├── PostingService               delivers one claimed item, applies retry/failure rules
    ├── ScheduleCalculator           pure next-run calculations (UTC in, UTC out)
    ├── CampaignBuilder              wizard/API persistence + sequence operations
    ├── RecoveryService              crash recovery
    └── BotService · DestinationService · ContentService · SettingsService · HealthService · AuditLogger
```

**Database tables:** `users`, `roles`, `permissions`, `permission_role`, `telegram_bots`, `telegram_destinations`, `contents`, `tags`, `content_tag`, `content_bot_files`, `campaigns`, `schedules`, `campaign_items`, `posting_logs`, `audit_logs`, `system_settings`, `notifications`, `personal_access_tokens`, plus Laravel's `jobs`, `failed_jobs`, `cache`, `sessions`.

All timestamps are stored in **UTC**; campaign timezones are used only to interpret wall-clock times such as "every day at 09:00".

---

## Requirements

* PHP **8.3+** (8.4 recommended) with `pdo_mysql`, `mbstring`, `openssl`, `curl`, `intl`, `fileinfo`, `zip`, `bcmath`, `redis` (phpredis)
* MySQL **8+** (MariaDB 10.6+ also works)
* Redis 6+
* Composer 2, Node.js 20+ and npm (only to build the frontend assets)
* Nginx + PHP-FPM, Supervisor, cron (production)

---

## Local installation

```bash
git clone <your-repo> telegram-automation && cd telegram-automation
composer install
npm install
npm run build
cp .env.example .env
php artisan key:generate
```

Edit `.env` (database credentials at minimum — see below), then:

```bash
php artisan migrate --seed            # tables + roles & permissions
php artisan app:create-admin          # first Super Admin (password typed interactively)
php artisan storage:link              # optional; the app streams files itself
php artisan serve                     # http://127.0.0.1:8000
```

In two more terminals, start a queue worker and the scheduler:

```bash
php artisan queue:work --queue=telegram,campaigns,default --sleep=1 --tries=3 --timeout=330
php artisan schedule:work
```

Optional demo data (the "Daily Videos" sample campaign from the specification, created as a **draft** — nothing is posted until you activate it):

```bash
php artisan db:seed --class=DemoSeeder
# or with a real bot and chat:
DEMO_BOT_TOKEN=123456:ABC... DEMO_CHAT_ID=@my_channel php artisan db:seed --class=DemoSeeder
```

> Local development without Redis: set `QUEUE_CONNECTION=database` and `CACHE_STORE=database` in `.env`.

---

## Environment variables

| Variable | Description |
|---|---|
| `APP_NAME`, `APP_URL` | Display name (can also be changed in Settings) and public URL (use `https://` in production). |
| `APP_ENV=production`, `APP_DEBUG=false` | **Always** in production. |
| `APP_KEY` | Generated by `php artisan key:generate`. **Encrypts the bot tokens** — back it up; if it is lost, every bot token must be re-entered. |
| `DB_*` | MySQL connection. |
| `REDIS_HOST`, `REDIS_PASSWORD`, `REDIS_PORT` | Redis connection. |
| `QUEUE_CONNECTION=redis` | Queue backend. |
| `REDIS_QUEUE_RETRY_AFTER=400` | Must be larger than the worker `--timeout` (330 s). |
| `CACHE_STORE=redis`, `SESSION_DRIVER=redis` | Cache (rate limits, flood cooldowns, job locks) and sessions. |
| `SESSION_SECURE_COOKIE=true`, `SESSION_ENCRYPT=true` | Secure sessions over HTTPS. |
| `MAIL_*` | Password reset e-mails and optional alert e-mails. |
| `FILESYSTEM_DISK`, `AWS_*` | Uploaded media storage. For S3-compatible storage run `composer require league/flysystem-aws-s3-v3 "^3.0"`, fill `AWS_*` (+ `AWS_ENDPOINT` for R2/Spaces/MinIO), then pick S3 in **Settings → Storage**. |
| `TELEGRAM_API_URL` | Default `https://api.telegram.org`; point it at a self-hosted Bot API server if you use one. |
| `TELEGRAM_TIMEOUT` | Default HTTP timeout (seconds); also editable in Settings. |
| `TELEGRAM_STALE_PROCESSING_MINUTES` | After how long an in-flight item is considered interrupted (default 10). |
| `AI_BASE_URL`, `AI_MODEL`, `AI_TIMEOUT` | Local Ollama server (default `http://127.0.0.1:11434`), default model (`qwen2.5:1.5b`, changeable in **Settings → AI**) and generation timeout in seconds (default 180). |

No passwords, tokens or credentials are hard-coded anywhere in the repository.

---

## Telegram setup

### 1. Create a bot
1. Open **@BotFather** in Telegram → `/newbot` → choose a name and a username ending in `bot`.
2. Copy the token (`123456789:AA…`).
3. Admin panel → **Telegram → Bots → Add bot**, paste the token, **Save & test connection**. The bot's @username fills in automatically.

### 2. Connect a channel
1. In Telegram open the channel → **Administrators → Add admin** → search for your bot → enable **Post messages**.
2. Admin panel → **Telegram → Channels → Add channel**: choose the bot, enter `@channelname` (public) or the numeric ID `-100…` (private).
3. Click **Test message**. The result appears in a few seconds (the test runs on the queue). On success the numeric chat ID is stored automatically.

**Groups:** add the bot to the group as a member (or admin) and make sure members may send messages. Groups upgraded to supergroups are followed automatically.

**Finding a private chat ID:** forward a message from the chat to `@userinfobot`, or temporarily add `@RawDataBot`.

### 3. Create content and a campaign
**Content → Content Library → New content**, then **Automation → Campaigns → New campaign** and follow the 10 steps. Turn on **Test mode** in step 3 to send everything to a test chat first.

---

## How campaigns run

* **One cron entry** runs `php artisan schedule:run` every minute. The scheduler runs `campaigns:dispatch-due` every **10 seconds**, which finds campaigns whose `next_run_at` has passed (or that have a due retry) and queues a `ProcessCampaignJob`.
* `ProcessCampaignJob` locks the campaign row (`SELECT … FOR UPDATE`), checks that no item is in flight, claims the next item with an atomic `pending → processing` update, writes a posting log, computes the next run time and queues the type-specific `SendTelegram…Job` **after the transaction commits**.
* The send job sets `api_called_at` with an atomic conditional update **before** calling Telegram, so two workers can never send the same attempt. The result is written to the log and the item (`processing → sent` or `processing → failed`).
* **Interval** slots are anchored to the start time (09:00, 09:30, 10:00 …). If the server was down, missed slots are skipped instead of posting a burst. A manual *Run Now* re-anchors interval campaigns ("every 30 minutes" means 30 minutes after the manual post too).
* **Failure behaviours**
  * *Retry, then continue* — temporary errors (network, timeout, 5xx) are retried `max_retries` times with exponential backoff (`delay × 2ⁿ`, capped at 1 h); then the item is marked failed and the campaign moves on.
  * *Stop campaign on failure* — same retries, then the campaign is marked **Failed** and the admins are alerted.
  * *Continue after failure* — no retries.
  * *Retry until successful* — retries forever with backoff until it succeeds or you **Skip** it.
  * Permanent errors (chat not found, bot not admin, invalid media, invalid token) are not retried, and the bot/destination is flagged.
* **Flood control (HTTP 429)** — the bot is put in a cooldown for exactly `retry_after` seconds. Send jobs for that bot are released until the cooldown ends, so no request is made during it. Flood waits do not use up the retry budget. A per-bot (default 25/s) and per-chat (default 20/min) limiter keeps you under Telegram's limits.
* **Crash recovery** (`campaigns:recover`, every minute and on every deploy) — items stuck in *processing*:
  * never reached Telegram → the job is queued again (safe);
  * may have reached Telegram → marked **failed** with an explanation and **not** re-sent automatically, so a restart never produces a duplicate post. Use **Retry** on the item after checking the chat.

---

## Production deployment on Ubuntu VPS

Tested on Ubuntu 24.04 LTS. Replace `your-domain.com` and paths as needed.

### 1. System packages, PHP, MySQL, Redis, Nginx, Supervisor
```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y software-properties-common curl git unzip ufw
sudo add-apt-repository -y ppa:ondrej/php && sudo apt update
sudo apt install -y php8.4-fpm php8.4-cli php8.4-mysql php8.4-redis php8.4-mbstring php8.4-xml php8.4-curl \
                    php8.4-zip php8.4-intl php8.4-bcmath php8.4-gd
sudo apt install -y mysql-server redis-server nginx supervisor
```

### 2. Composer and Node.js
```bash
curl -sS https://getcomposer.org/installer | php && sudo mv composer.phar /usr/local/bin/composer
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash - && sudo apt install -y nodejs
```

### 3. Database
```bash
sudo mysql_secure_installation
sudo mysql
```
```sql
CREATE DATABASE telegram_automation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'telegram_app'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON telegram_automation.* TO 'telegram_app'@'localhost';
FLUSH PRIVILEGES;
```

### 4. Redis
In `/etc/redis/redis.conf` set `supervised systemd` and a password (`requirepass …`), then `sudo systemctl restart redis-server`. Put the same password in `REDIS_PASSWORD`.

### 5. Application
```bash
sudo mkdir -p /var/www/telegram-automation && sudo chown $USER:www-data /var/www/telegram-automation
git clone <your-repo> /var/www/telegram-automation
cd /var/www/telegram-automation
composer install --no-dev --optimize-autoloader
npm ci && npm run build
cp .env.example .env && nano .env        # fill in DB_*, REDIS_*, APP_URL, MAIL_*
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force              # roles & permissions, starter plans
php artisan app:create-admin             # first Super Admin
php artisan storage:link
php artisan optimize
```

### 6. Permissions
```bash
sudo chown -R $USER:www-data /var/www/telegram-automation
sudo find /var/www/telegram-automation -type f -exec chmod 644 {} \;
sudo find /var/www/telegram-automation -type d -exec chmod 755 {} \;
sudo chgrp -R www-data storage bootstrap/cache
sudo chmod -R ug+rwx storage bootstrap/cache
chmod 600 .env
```

### 7. Nginx
```bash
sudo cp deploy/nginx/telegram-automation.conf /etc/nginx/sites-available/telegram-automation
sudo nano /etc/nginx/sites-available/telegram-automation     # set server_name
sudo ln -s /etc/nginx/sites-available/telegram-automation /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```

### 8. SSL (Let's Encrypt)
```bash
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```
Certificates renew automatically (`systemctl list-timers | grep certbot`). Make sure `APP_URL` starts with `https://` and `SESSION_SECURE_COOKIE=true`.

### 9. Queue workers (Supervisor)
```bash
sudo cp deploy/supervisor/telegram-automation-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start "telegram-automation-worker:*"
sudo supervisorctl status
```
Workers start on boot, restart automatically if they crash, and are recycled hourly. After every deploy run `php artisan queue:restart` (the deploy script does this).

### 10. Scheduler (cron)
```bash
sudo cp deploy/cron/telegram-automation /etc/cron.d/telegram-automation
sudo chmod 644 /etc/cron.d/telegram-automation
```
This single entry drives everything; **System → System Health** shows *Scheduler: Healthy* within a minute.

### 11. Firewall
```bash
sudo ufw allow OpenSSH
sudo ufw allow 'Nginx Full'
sudo ufw enable
```
MySQL (3306) and Redis (6379) stay bound to `127.0.0.1` and are not opened.

### 12. Offline AI (optional)
Installs [Ollama](https://ollama.com), binds it to `127.0.0.1` only (port 11434 is never opened), runs one generation at a time, frees the RAM after 5 idle minutes and downloads a small model (~1 GB):
```bash
curl -fsSL https://ollama.com/install.sh | sh
sudo mkdir -p /etc/systemd/system/ollama.service.d
printf '[Service]\nEnvironment="OLLAMA_HOST=127.0.0.1:11434"\nEnvironment="OLLAMA_NUM_PARALLEL=1"\nEnvironment="OLLAMA_MAX_LOADED_MODELS=1"\nEnvironment="OLLAMA_KEEP_ALIVE=5m"\n' | sudo tee /etc/systemd/system/ollama.service.d/override.conf
sudo systemctl daemon-reload && sudo systemctl restart ollama
ollama pull qwen2.5:1.5b
```
Then **Settings → AI (offline)** → enable → *AI server check karein*. On 2 CPU cores a short draft takes roughly 10–60 s; drafts run on the `default` queue, one at a time, so posting is never blocked. Use a normal chat model — "thinking-only" models are detected and rejected.

### 13. Updating
```bash
bash deploy/deploy.sh
```

### Backups
Back up the database (`mysqldump`), `storage/app` (uploaded media) and the `.env` file (**APP_KEY** decrypts the bot tokens).

---

## REST API

Base URL: `https://your-domain.com/api`. Authenticate with a personal access token (create one in **Profile → API tokens** or via the login endpoint) and send `Authorization: Bearer <token>` and `Accept: application/json`. Each endpoint enforces the same permissions as the admin panel. Bot tokens are never returned (only `masked_token`).

| Method | Endpoint | Description |
|---|---|---|
| POST | `/auth/token` | `{email, password, device_name}` → `{token}` (throttled) |
| DELETE | `/auth/token` | Revoke the current token |
| GET | `/me` | Current user, role, permissions |
| GET/POST | `/bots` | List (`search`, `status`) / create `{name, token, status, description}` |
| GET/PUT/DELETE | `/bots/{id}` | Show / update / delete |
| POST | `/bots/{id}/test` | Run `getMe` |
| GET/POST | `/destinations` | List (`bot_id`, `type`, `status`, `search`) / create `{bot_id, name, chat_id, type, status}` |
| GET/PUT/DELETE | `/destinations/{id}` | Show / update / delete |
| POST | `/destinations/{id}/test` | Queue a connection test (`send_message`, default true) |
| GET/POST | `/contents` | List (`search`, `type`, `status`, `tag`) / create (JSON, or multipart with `upload`) |
| GET/PUT/DELETE | `/contents/{id}` | Show / partial update / delete |
| GET/POST | `/campaigns` | List (`search`, `status`, `bot_id`, `destination_id`) / create (`items`, `slots`, optional `activate: true`) |
| GET/PUT/DELETE | `/campaigns/{id}` | Show (with items & progress) / update / delete |
| POST | `/campaigns/{id}/activate` · `/run` · `/pause` · `/resume` · `/stop` · `/restart` | Lifecycle actions |
| POST | `/campaigns/{id}/items/{item}/skip` · `/retry` | Item actions |
| GET | `/campaigns/{id}/logs` | Posting logs of a campaign |
| GET | `/logs` | All logs (`campaign_id`, `bot_id`, `destination_id`, `status`, `from`, `to`, `with_response=1`) |

Example — create and start a campaign:

```bash
curl -X POST https://your-domain.com/api/campaigns \
  -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
    "name": "Daily Videos", "bot_id": 1, "destination_id": 1,
    "posting_order": "sequential", "trigger_type": "interval", "interval_seconds": 1800,
    "timezone": "Asia/Karachi", "start_at": "2026-10-20 09:00",
    "repeat_mode": "none", "failure_behavior": "retry_then_continue", "max_retries": 3, "retry_delay_seconds": 60,
    "items": [{"content_id": 1}, {"content_id": 2}, {"content_id": 3}],
    "activate": true
  }'
```

Validation errors return `422` with an `errors` object; missing permissions return `403`; rate limits return `429`.

---

## Testing

```bash
php artisan test
```

The suite (105 tests) never calls the real Telegram API — `tests/Support/FakeTelegram.php` emulates the Bot API and `Http::preventStrayRequests()` blocks any other outgoing request. It covers:

* **Unit** — schedule calculations (intervals, fixed times, weekly, timezones, start/end dates), Telegram error classification, token redaction.
* **Engine integration** — the sample campaign end to end (09:00 → 11:00 → Completed), pause/resume, Run Now, manual trigger, exactly-once delivery under concurrent workers, flood control and cooldown, retry/backoff, stop-on-failure, permanent errors, file_id caching and fallback, crash recovery (re-queue vs. never re-send), restart with preserved history, skip, test mode, random mode, weekly trigger, daily repeat, exact-time trigger, multiple bots/campaigns, inactive content, secrets never stored.
* **Feature** — login/logout/throttling/disabled accounts/password reset, bot creation & validation & encryption, destinations & verification, the campaign wizard, details-page actions, sequence reordering, permissions, every admin page, and the REST API.

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| Nothing is posted, *Scheduler: Failed* | The cron entry is missing or cannot run: `sudo cat /etc/cron.d/telegram-automation`, check `php artisan schedule:list`. |
| *Queue workers: Failed* | `sudo supervisorctl status`; read `storage/logs/worker.log`. Workers must listen to `telegram,campaigns,default`. |
| "The bot token is invalid or was revoked" | Generate a new token in @BotFather (`/revoke`) and update the bot. |
| "Chat not found" | Wrong ID/username, or the bot was never added to the chat. Private chats need the numeric `-100…` ID. |
| "The bot lacks permissions" / not an administrator | Channel → Administrators → your bot → enable **Post messages**. |
| "Telegram could not use the media" | The URL must be public, HTTPS, point directly to the file (not an HTML page) and be under 5 MB (photos) / 20 MB (other). Use **Check URL** in the content form, or upload the file instead (up to 50 MB). |
| Items show "Delivery was interrupted…" | A worker died while talking to Telegram. Check the chat; if the post is missing, click **Retry** on the item. |
| Changes in `.env` have no effect | `php artisan optimize:clear && php artisan optimize && php artisan queue:restart`. |
| "MAC is invalid" errors | `APP_KEY` changed. Restore the previous key, or re-enter every bot token. |
| Health page: Redis warning in local development | Expected when using the database queue/cache locally. |

Logs: `storage/logs/laravel.log` (application), `telegram-*.log` (Bot API calls), `queue-*.log`, `campaign-*.log`. Bot tokens and secrets are redacted from every log channel.

---

## Extending the platform

* **Other platforms** (Discord, WhatsApp, Facebook Pages, e-mail …): implement `App\Contracts\Publisher` and register it in `config/publishing.php`. `PostingService`, the jobs, logging and retry logic are platform-neutral.
* **Multiple destinations per campaign**: `Campaign::destinations()` and per-log `destination_id` are already in place; extend the claim step to create one log per destination.
* **Content sources** (RSS, Google Sheets, JSON/REST feeds, AI captions): create `Content` records through `ContentService::create()` from a scheduled command or a queued job; campaigns will pick them up like any other content.
* **New permissions/roles**: add the slug to `App\Support\Permissions` and run `php artisan db:seed --class=RolesAndPermissionsSeeder`.
* **Accounts**: a role with `accounts.view-all` is staff (sees every account, no plan limits); every other role is a customer. New account-owned tables use the `BelongsToAccount` trait (column `user_id`) and `Accounts::exists()` in validation rules. New plan limits go in `plans`, `Plan::RESOURCES / SENDING / FEATURES` and `PlanLimiter`.
