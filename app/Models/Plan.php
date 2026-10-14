<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A monthly subscription plan. Limits that are null mean "unlimited".
 */
#[Fillable([
    'name', 'slug', 'description', 'price_usd', 'is_default', 'is_active', 'is_featured', 'sort_order',
    'max_bots', 'max_channels', 'max_groups', 'max_campaigns', 'max_contents',
    'posts_per_day', 'posts_per_week', 'posts_per_month', 'min_interval_minutes', 'max_upload_mb',
    'allow_uploads', 'allow_ai', 'allow_api', 'extra_features',
])]
class Plan extends Model
{
    /** Countable resources: limit column => label. */
    public const RESOURCES = [
        'max_bots' => 'Bots',
        'max_channels' => 'Channels',
        'max_groups' => 'Groups',
        'max_campaigns' => 'Campaigns',
        'max_contents' => 'Content items',
    ];

    /** Sending limits: limit column => period. */
    public const SENDING = [
        'posts_per_day' => 'day',
        'posts_per_week' => 'week',
        'posts_per_month' => 'month',
    ];

    /** On/off features: column => label. */
    public const FEATURES = [
        'allow_uploads' => 'Media uploads',
        'allow_ai' => 'AI post writer',
        'allow_api' => 'REST API access',
    ];

    protected function casts(): array
    {
        return [
            'price_usd' => 'decimal:2',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'allow_uploads' => 'boolean',
            'allow_ai' => 'boolean',
            'allow_api' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Plan $plan) {
            $plan->slug = $plan->slug ?: Str::slug($plan->name);
        });
    }

    /**
     * The free plan every account falls back to.
     */
    public static function fallback(): ?self
    {
        return static::query()->where('is_default', true)->orderBy('id')->first();
    }

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasMany<PlanOrder, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(PlanOrder::class);
    }

    /**
     * @param  Builder<Plan>  $query
     */
    public function scopeForSale(Builder $query): void
    {
        $query->where('is_active', true)->orderBy('sort_order')->orderBy('price_usd');
    }

    public function isFree(): bool
    {
        return (float) $this->price_usd <= 0;
    }

    public function limit(string $column): ?int
    {
        $value = $this->getAttribute($column);

        return $value === null ? null : (int) $value;
    }

    public function formattedPrice(): string
    {
        return $this->isFree() ? 'Free' : '$'.rtrim(rtrim(number_format((float) $this->price_usd, 2), '0'), '.');
    }

    public static function formatLimit(?int $value): string
    {
        return $value === null ? 'Unlimited' : number_format($value);
    }

    /**
     * Human-readable list for pricing cards.
     *
     * @return list<string>
     */
    public function highlights(): array
    {
        $lines = [];

        foreach (self::RESOURCES as $column => $label) {
            $count = $this->limit($column);
            $lines[] = self::formatLimit($count).' '.Str::plural(Str::singular(Str::lower($label)), $count ?? 2);
        }

        foreach (self::SENDING as $column => $period) {
            $lines[] = self::formatLimit($this->limit($column))." posts / {$period}";
        }

        $lines[] = 'Posting interval from '.max(1, (int) $this->min_interval_minutes).' min';

        if ($this->allow_uploads) {
            $lines[] = 'Media uploads'.($this->max_upload_mb ? " up to {$this->max_upload_mb} MB" : '');
        }

        if ($this->allow_ai) {
            $lines[] = 'AI post writer';
        }

        if ($this->allow_api) {
            $lines[] = 'REST API access';
        }

        foreach (preg_split('/\R/', (string) $this->extra_features) as $extra) {
            if (trim($extra) !== '') {
                $lines[] = trim($extra);
            }
        }

        return $lines;
    }
}
