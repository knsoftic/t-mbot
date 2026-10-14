<?php

namespace App\Services;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Database-backed system settings with defaults and a cache layer.
 */
class SettingsService
{
    private const CACHE_KEY = 'system_settings.v1';

    /**
     * @var array<string, mixed>|null
     */
    private ?array $loaded = null;

    /**
     * Every supported setting with its default value.
     *
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'general.app_name' => config('app.name', 'Telegram Automation'),
            'general.logo_path' => null,
            'general.timezone' => 'Asia/Karachi',
            'general.default_campaign_timezone' => 'Asia/Karachi',

            'telegram.api_timeout' => (int) config('telegram.timeout', 30),
            'telegram.default_max_retries' => 3,
            'telegram.default_retry_delay' => 60,
            'telegram.per_bot_per_second' => 25,
            'telegram.per_chat_per_minute' => 20,
            'telegram.allow_http_media' => false,
            'telegram.test_message' => "✅ Test message from {app}.\nThe bot can post in this chat.",

            'notifications.mail_enabled' => false,
            'notifications.failure_threshold' => 3,

            'storage.media_disk' => 'local',

            'ai.enabled' => false,
            'ai.model' => config('ai.model', 'qwen2.5:1.5b'),
            'ai.default_language' => 'english',

            'registration.enabled' => true,
            // required | optional | hidden
            'registration.phone' => 'required',

            // Contact details asked for on the plan purchase form: required | optional | hidden
            'billing.contact_name' => 'required',
            'billing.contact_email' => 'required',
            'billing.contact_phone' => 'optional',
            'billing.contact_whatsapp' => 'required',
            'billing.instructions' => 'Send the exact amount to the address shown, then enter the transaction ID / reference below. We verify every payment by hand and activate your plan, usually within a few hours.',
            'billing.support_whatsapp' => null,
            'billing.support_email' => null,
        ];
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        if (array_key_exists($key, $all) && $all[$key] !== null) {
            return $all[$key];
        }

        return $default ?? (self::defaults()[$key] ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->loaded !== null) {
            return $this->loaded;
        }

        try {
            $stored = Cache::rememberForever(self::CACHE_KEY, function () {
                if (! Schema::hasTable('system_settings')) {
                    return [];
                }

                return SystemSetting::query()->pluck('value', 'key')
                    ->map(fn ($value) => json_decode((string) $value, true))
                    ->all();
            });
        } catch (Throwable) {
            // Database or cache unavailable (fresh install, health check): fall back to defaults.
            $stored = [];
        }

        return $this->loaded = array_merge(self::defaults(), $stored);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public function set(array $values): void
    {
        foreach ($values as $key => $value) {
            SystemSetting::query()->updateOrCreate(['key' => $key], ['value' => json_encode($value)]);
        }

        $this->flush();
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->loaded = null;
    }

    public function appName(): string
    {
        return (string) $this->get('general.app_name');
    }

    public function timezone(): string
    {
        return (string) $this->get('general.timezone');
    }

    public function mediaDisk(): string
    {
        $disk = (string) $this->get('storage.media_disk');

        return array_key_exists($disk, config('filesystems.disks')) ? $disk : 'local';
    }
}
