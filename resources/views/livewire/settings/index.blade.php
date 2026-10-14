<div>
    <x-page-header title="System Settings" description="Changes apply immediately to the panel and to queue workers (each job reads fresh settings)." />

    <div class="grid gap-6 lg:grid-cols-4">
        <nav class="card h-fit p-2 lg:col-span-1">
            @foreach (['general' => ['General', 'cog'], 'telegram' => ['Telegram', 'send'], 'ai' => ['AI (offline)', 'sparkles'], 'queue' => ['Queue', 'list'], 'storage' => ['Storage', 'server'], 'notifications' => ['Notifications', 'bell']] as $key => [$label, $icon])
                <button type="button" wire:click="$set('tab', '{{ $key }}')" @class([
                    'flex w-full items-center gap-2 rounded-lg px-3 py-2 text-sm font-medium',
                    'bg-brand-50 text-brand-700 dark:bg-brand-500/15 dark:text-brand-200' => $tab === $key,
                    'text-slate-600 hover:bg-slate-50 dark:text-slate-300 dark:hover:bg-slate-800' => $tab !== $key,
                ])><x-icon :name="$icon" class="size-4" /> {{ $label }}</button>
            @endforeach
        </nav>

        <form wire:submit="save" class="card space-y-5 p-6 lg:col-span-3">
            @if ($tab === 'general')
                <h2 class="card-title">General</h2>
                <x-field label="Application name" for="form.general.app_name" required>
                    <input id="form.general.app_name" type="text" wire:model="form.general.app_name" class="input">
                </x-field>
                <div>
                    <span class="label">Logo</span>
                    <div class="flex items-center gap-4">
                        <div class="flex size-14 items-center justify-center overflow-hidden rounded-xl bg-slate-100 ring-1 ring-slate-200 dark:bg-slate-800 dark:ring-slate-700">
                            @if ($logo && $logo->isPreviewable())
                                <img src="{{ $logo->temporaryUrl() }}" alt="" class="size-full object-contain">
                            @elseif (! empty($form['general']['logo_path']))
                                <img src="{{ route('branding.logo') }}?v={{ md5($form['general']['logo_path']) }}" alt="" class="size-full object-contain">
                            @else
                                <x-icon name="photo" class="size-6 text-slate-400" />
                            @endif
                        </div>
                        <input type="file" wire:model="logo" accept=".png,.jpg,.jpeg,.webp,.svg" class="text-sm">
                        @if (! empty($form['general']['logo_path']))
                            <button type="button" wire:click="removeLogo" class="btn btn-ghost btn-sm">Remove</button>
                        @endif
                    </div>
                    @error('logo') <p class="error">{{ $message }}</p> @enderror
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Display timezone" for="form.general.timezone" hint="Used to show dates in the panel (users can override in their profile).">
                        <select id="form.general.timezone" wire:model="form.general.timezone" class="input">
                            @foreach ($timezones as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                        </select>
                    </x-field>
                    <x-field label="Default campaign timezone" for="form.general.default_campaign_timezone" hint="Pre-selected in the campaign wizard.">
                        <select id="form.general.default_campaign_timezone" wire:model="form.general.default_campaign_timezone" class="input">
                            @foreach ($timezones as $tz) <option value="{{ $tz }}">{{ $tz }}</option> @endforeach
                        </select>
                    </x-field>
                </div>
                <p class="text-xs text-slate-500">All timestamps are stored in UTC and converted for display and scheduling.</p>
            @elseif ($tab === 'telegram')
                <h2 class="card-title">Telegram</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="API timeout (seconds)" for="form.telegram.api_timeout" hint="Media uploads automatically get more time.">
                        <input id="form.telegram.api_timeout" type="number" wire:model="form.telegram.api_timeout" class="input">
                    </x-field>
                    <x-field label="Default maximum retries" for="form.telegram.default_max_retries">
                        <input id="form.telegram.default_max_retries" type="number" wire:model="form.telegram.default_max_retries" class="input">
                    </x-field>
                    <x-field label="Default retry delay (seconds)" for="form.telegram.default_retry_delay" hint="Doubles on every retry (exponential backoff).">
                        <input id="form.telegram.default_retry_delay" type="number" wire:model="form.telegram.default_retry_delay" class="input">
                    </x-field>
                    <x-field label="Max requests per bot per second" for="form.telegram.per_bot_per_second" hint="Telegram allows about 30.">
                        <input id="form.telegram.per_bot_per_second" type="number" wire:model="form.telegram.per_bot_per_second" class="input">
                    </x-field>
                    <x-field label="Max posts per chat per minute" for="form.telegram.per_chat_per_minute" hint="Telegram allows about 20 in groups.">
                        <input id="form.telegram.per_chat_per_minute" type="number" wire:model="form.telegram.per_chat_per_minute" class="input">
                    </x-field>
                    <div class="flex items-end pb-2">
                        <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.telegram.allow_http_media" class="checkbox"> Allow plain http:// media URLs</label>
                    </div>
                </div>
                <x-field label="Destination test message" for="form.telegram.test_message" hint="{app} is replaced by the application name.">
                    <textarea id="form.telegram.test_message" wire:model="form.telegram.test_message" rows="2" class="input"></textarea>
                </x-field>
                <div class="rounded-lg bg-brand-50 p-3 text-xs text-brand-900 dark:bg-brand-500/10 dark:text-brand-200">
                    <strong>Rate-limit handling is always on:</strong> when Telegram answers 429 with <span class="kbd-pill">retry_after</span>, the bot pauses for exactly that long and no requests are sent during the cooldown.
                </div>
            @elseif ($tab === 'ai')
                <h2 class="card-title">AI (offline, local model)</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">AI isi server par chalne wale model se kaam karta hai (Ollama, <span class="kbd-pill">{{ config('ai.base_url') }}</span>). Koi data bahar kisi AI company ko nahi jata aur koi API key nahi chahiye. On hone par Content form mein <strong>✨ AI se likho</strong> button aa jata hai; AI sirf draft likhta hai, post khud nahi karta.</p>
                <label class="flex items-center gap-2 text-sm font-medium"><input type="checkbox" wire:model="form.ai.enabled" class="checkbox"> AI drafts on karein</label>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-field label="Model" for="form.ai.model" hint="Server par 'ollama pull <model>' se install kiya hua naam, jaise qwen2.5:1.5b.">
                        <input id="form.ai.model" type="text" wire:model="form.ai.model" class="input font-mono">
                    </x-field>
                    <x-field label="Default zaban" for="form.ai.default_language">
                        <select id="form.ai.default_language" wire:model="form.ai.default_language" class="input">
                            @foreach (\App\Services\Ai\PostWriter::LANGUAGE_LABELS as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
                <div class="flex flex-wrap items-center gap-3">
                    <button type="button" wire:click="checkAi" class="btn btn-secondary" wire:loading.attr="disabled" wire:target="checkAi">
                        <span wire:loading wire:target="checkAi"><x-spinner /></span> AI server check karein
                    </button>
                    @if ($aiCheck)
                        <span @class(['text-sm', 'text-emerald-600 dark:text-emerald-400' => $aiCheck['ok'], 'text-red-600 dark:text-red-400' => ! $aiCheck['ok']])>{{ $aiCheck['message'] }}</span>
                    @endif
                </div>
                @if ($aiCheck && $aiCheck['models'])
                    <p class="text-xs text-slate-500">Installed models: @foreach ($aiCheck['models'] as $m)<span class="kbd-pill">{{ $m }}</span> @endforeach</p>
                @endif
                <x-docs.callout type="info">Server par Ollama install karne ka tareeqa <a href="{{ route('help.guide') }}#ai" wire:navigate class="underline">User Guide → AI</a> mein hai. 2 CPU / 8 GB RAM wale server par 1–1.5B model ki salah hai.</x-docs.callout>
            @elseif ($tab === 'queue')
                <h2 class="card-title">Queue</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">The queue connection is infrastructure configuration and is set in <span class="kbd-pill">.env</span> (changing it at runtime would orphan queued jobs). Current values:</p>
                <dl class="grid gap-2 text-sm sm:grid-cols-2">
                    @foreach ([
                        'QUEUE_CONNECTION' => config('queue.default'),
                        'Campaign queue' => config('telegram.queues.campaigns'),
                        'Telegram queue' => config('telegram.queues.telegram'),
                        'Retry after (s)' => config('queue.connections.'.config('queue.default').'.retry_after', '—'),
                        'CACHE_STORE' => config('cache.default'),
                        'Stale processing threshold' => config('telegram.stale_processing_minutes').' minutes',
                    ] as $label => $value)
                        <div class="flex justify-between rounded-lg bg-slate-50 px-3 py-2 dark:bg-slate-800/50"><dt class="text-slate-500">{{ $label }}</dt><dd class="font-mono">{{ $value }}</dd></div>
                    @endforeach
                </dl>
                <div>
                    <div class="label">Worker command (Supervisor)</div>
                    <pre class="overflow-x-auto rounded-lg bg-slate-900 p-3 font-mono text-xs text-slate-100">php artisan queue:work {{ config('queue.default') }} --queue={{ config('telegram.queues.telegram') }},{{ config('telegram.queues.campaigns') }},default --sleep=1 --tries=3 --timeout=330 --max-time=3600</pre>
                </div>
                <p class="text-xs text-slate-500">See the README for the complete Supervisor and cron configuration.</p>
            @elseif ($tab === 'storage')
                <h2 class="card-title">Storage</h2>
                <x-field label="Disk for uploaded media" for="form.storage.media_disk" hint="Files are never stored in the database — only the path, size, MIME type and Telegram file_id.">
                    <select id="form.storage.media_disk" wire:model="form.storage.media_disk" class="input sm:w-80">
                        <option value="local">Local (private, on this server)</option>
                        <option value="s3" @disabled(! $s3Available)>S3-compatible (AWS, R2, Spaces, MinIO){{ $s3Available ? '' : ' — not configured' }}</option>
                    </select>
                </x-field>
                @unless ($s3Available)
                    <div class="rounded-lg bg-amber-50 p-3 text-xs text-amber-900 dark:bg-amber-500/10 dark:text-amber-200">
                        S3 is unavailable: {{ $s3Installed ? 'set AWS_ACCESS_KEY_ID, AWS_SECRET_ACCESS_KEY, AWS_BUCKET (and AWS_ENDPOINT for non-AWS providers) in .env.' : 'install the driver with “composer require league/flysystem-aws-s3-v3 \"^3.0\"” and set the AWS_* variables in .env.' }}
                    </div>
                @endunless
            @else
                <h2 class="card-title">Notifications</h2>
                <p class="text-sm text-slate-600 dark:text-slate-300">Admins always see alerts in the bell menu (campaign started / completed / failed, bot disconnected, permission problems, repeated failures).</p>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="form.notifications.mail_enabled" class="checkbox"> Also send alerts by e-mail (uses the MAIL_* settings in .env)</label>
                <x-field label="Alert after this many consecutive failed posts" for="form.notifications.failure_threshold">
                    <input id="form.notifications.failure_threshold" type="number" wire:model="form.notifications.failure_threshold" class="input w-32">
                </x-field>
            @endif

            @if ($tab !== 'queue')
                <div class="flex justify-end border-t border-slate-100 pt-4 dark:border-slate-800">
                    <button type="submit" class="btn btn-primary" wire:loading.attr="disabled" wire:target="save,logo"><span wire:loading wire:target="save"><x-spinner /></span> Save settings</button>
                </div>
            @endif
        </form>
    </div>
</div>
