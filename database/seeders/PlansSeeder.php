<?php

namespace Database\Seeders;

use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Starter plans (USD / month). Runs only while there are no plans, so prices
 * and limits changed in the admin panel are never overwritten.
 */
class PlansSeeder extends Seeder
{
    public function run(): void
    {
        if (Plan::query()->exists()) {
            return;
        }

        $plans = [
            [
                'name' => 'Free', 'slug' => 'free', 'description' => 'Try the platform with one bot and one channel.',
                'price_usd' => 0, 'is_default' => true, 'sort_order' => 0,
                'max_bots' => 1, 'max_channels' => 1, 'max_groups' => 1, 'max_campaigns' => 2, 'max_contents' => 50,
                'posts_per_day' => 10, 'posts_per_week' => 50, 'posts_per_month' => 150,
                'min_interval_minutes' => 30, 'max_upload_mb' => 10,
                'allow_uploads' => true, 'allow_ai' => false, 'allow_api' => false,
            ],
            [
                'name' => 'Plan 1', 'slug' => 'plan-1', 'description' => 'Starter: for one brand with a few channels.',
                'price_usd' => 5, 'sort_order' => 1,
                'max_bots' => 2, 'max_channels' => 5, 'max_groups' => 5, 'max_campaigns' => 10, 'max_contents' => 500,
                'posts_per_day' => 100, 'posts_per_week' => 600, 'posts_per_month' => 2000,
                'min_interval_minutes' => 5, 'max_upload_mb' => 20,
                'allow_uploads' => true, 'allow_ai' => true, 'allow_api' => false,
            ],
            [
                'name' => 'Plan 2', 'slug' => 'plan-2', 'description' => 'Pro: several bots, many channels and the API.',
                'price_usd' => 12, 'is_featured' => true, 'sort_order' => 2,
                'max_bots' => 5, 'max_channels' => 20, 'max_groups' => 20, 'max_campaigns' => 50, 'max_contents' => 5000,
                'posts_per_day' => 500, 'posts_per_week' => 3000, 'posts_per_month' => 10000,
                'min_interval_minutes' => 1, 'max_upload_mb' => 50,
                'allow_uploads' => true, 'allow_ai' => true, 'allow_api' => true,
                'extra_features' => 'Priority support',
            ],
            [
                'name' => 'Plan 3', 'slug' => 'plan-3', 'description' => 'Business: agencies and large networks.',
                'price_usd' => 25, 'sort_order' => 3,
                'max_bots' => 15, 'max_channels' => 100, 'max_groups' => 100, 'max_campaigns' => null, 'max_contents' => null,
                'posts_per_day' => 2000, 'posts_per_week' => 12000, 'posts_per_month' => 40000,
                'min_interval_minutes' => 1, 'max_upload_mb' => 50,
                'allow_uploads' => true, 'allow_ai' => true, 'allow_api' => true,
                'extra_features' => "Priority support\nHelp with setup",
            ],
        ];

        foreach ($plans as $plan) {
            Plan::query()->create($plan);
        }
    }
}
