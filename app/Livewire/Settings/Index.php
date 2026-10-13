<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\InteractsWithUi;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\Permissions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

#[Title('Settings')]
class Index extends Component
{
    use InteractsWithUi, WithFileUploads;

    #[Url(except: 'general')]
    public string $tab = 'general';

    /** @var array<string, array<string, mixed>> */
    public array $form = [];

    /** @var TemporaryUploadedFile|null */
    public $logo = null;

    public function mount(SettingsService $settings): void
    {
        $this->authorize(Permissions::SETTINGS_MANAGE);
        $this->form = Arr::undot($settings->all());
    }

    public static function s3Available(): bool
    {
        return class_exists(AwsS3V3Adapter::class) && filled(config('filesystems.disks.s3.bucket'));
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsRules(): array
    {
        return [
            'form.general.app_name' => ['required', 'string', 'max:60'],
            'form.general.timezone' => ['required', 'timezone:all'],
            'form.general.default_campaign_timezone' => ['required', 'timezone:all'],
            'form.telegram.api_timeout' => ['required', 'integer', 'min:5', 'max:120'],
            'form.telegram.default_max_retries' => ['required', 'integer', 'min:0', 'max:20'],
            'form.telegram.default_retry_delay' => ['required', 'integer', 'min:5', 'max:86400'],
            'form.telegram.per_bot_per_second' => ['required', 'integer', 'min:1', 'max:30'],
            'form.telegram.per_chat_per_minute' => ['required', 'integer', 'min:1', 'max:60'],
            'form.telegram.allow_http_media' => ['boolean'],
            'form.telegram.test_message' => ['required', 'string', 'max:1000'],
            'form.notifications.mail_enabled' => ['boolean'],
            'form.notifications.failure_threshold' => ['required', 'integer', 'min:2', 'max:50'],
            'form.storage.media_disk' => ['required', 'in:local'.(self::s3Available() ? ',s3' : '')],
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
        ];
    }

    public function save(SettingsService $settings, AuditLogger $audit): void
    {
        $this->authorize(Permissions::SETTINGS_MANAGE);
        $this->validate($this->settingsRules(), [], [
            'form.storage.media_disk' => 'storage disk',
            'form.general.app_name' => 'application name',
        ]);

        $values = Arr::dot($this->form);

        if ($this->logo) {
            $path = $this->logo->storeAs('branding', 'logo-'.Str::lower(Str::random(8)).'.'.$this->logo->getClientOriginalExtension(), ['disk' => 'public']);
            $old = $settings->get('general.logo_path');

            if ($old) {
                Storage::disk('public')->delete($old);
            }

            $values['general.logo_path'] = $path;
            $this->logo = null;
        }

        // Only persist known settings, cast to the default's type.
        $defaults = SettingsService::defaults();
        $before = $settings->all();
        $clean = [];

        foreach ($defaults as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            $clean[$key] = match (true) {
                is_bool($default) => (bool) $values[$key],
                is_int($default) => (int) $values[$key],
                default => $values[$key],
            };
        }

        $settings->set($clean);
        $changed = collect($clean)->filter(fn ($v, $k) => ($before[$k] ?? null) !== $v)->map(fn ($v, $k) => ['from' => $before[$k] ?? null, 'to' => $v])->all();
        $audit->log('settings.updated', null, 'System settings changed', $changed);

        $this->form = Arr::undot($settings->all());
        $this->toast('Settings saved.');
    }

    public function removeLogo(SettingsService $settings, AuditLogger $audit): void
    {
        $this->authorize(Permissions::SETTINGS_MANAGE);

        if ($path = $settings->get('general.logo_path')) {
            Storage::disk('public')->delete($path);
        }

        $settings->set(['general.logo_path' => null]);
        $audit->log('settings.updated', null, 'Logo removed');
        $this->form = Arr::undot($settings->all());
        $this->toast('Logo removed.');
    }

    public function render()
    {
        return view('livewire.settings.index', [
            'timezones' => \DateTimeZone::listIdentifiers(),
            's3Available' => self::s3Available(),
            's3Installed' => class_exists(AwsS3V3Adapter::class),
        ]);
    }
}
