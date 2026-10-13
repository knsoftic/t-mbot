<?php

namespace Database\Seeders;

use App\Enums\BotStatus;
use App\Enums\CampaignStatus;
use App\Enums\ContentSource;
use App\Enums\ContentStatus;
use App\Enums\ContentType;
use App\Enums\DestinationStatus;
use App\Enums\DestinationType;
use App\Enums\FailureBehavior;
use App\Enums\ItemStatus;
use App\Enums\PostingOrder;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use App\Models\Campaign;
use App\Models\Content;
use App\Models\Tag;
use App\Models\TelegramBot;
use App\Models\TelegramDestination;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;

/**
 * Sample "Daily Videos" campaign (spec section 55).
 *
 * Nothing is posted until an administrator activates the campaign. Provide a
 * real bot/chat through the environment to get a working demo:
 *
 *   DEMO_BOT_TOKEN=123456:ABC... DEMO_CHAT_ID=@mychannel php artisan db:seed --class=DemoSeeder
 *
 * Without them the bot is created disabled with a placeholder token, and
 * the media URLs point at example.com: replace them before activating.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $token = env('DEMO_BOT_TOKEN');
        $chatId = env('DEMO_CHAT_ID', '@my_channel');
        $hasRealBot = is_string($token) && preg_match('/^\d{5,}:[A-Za-z0-9_-]{30,}$/', $token);

        $bot = TelegramBot::query()->where('name', 'Marketing Bot')->first() ?? TelegramBot::create([
            'name' => 'Marketing Bot',
            'token' => $hasRealBot ? $token : '100000000:REPLACE_WITH_THE_TOKEN_FROM_BOTFATHER',
            'status' => $hasRealBot ? BotStatus::Active : BotStatus::Disabled,
            'description' => $hasRealBot ? 'Demo bot' : 'Demo bot — edit it and paste a real token from @BotFather, then click Test.',
        ]);

        $destination = TelegramDestination::query()->firstOrCreate(
            ['bot_id' => $bot->id, 'chat_id' => TelegramDestination::normalizeChatId($chatId)],
            [
                'name' => 'My Channel',
                'type' => DestinationType::Channel,
                'status' => DestinationStatus::Active,
                'description' => 'Demo channel — make the bot an administrator with "Post messages", then click Test.',
            ],
        );

        $tag = Tag::query()->firstOrCreate(['slug' => 'demo'], ['name' => 'Demo', 'color' => 'indigo']);

        $definitions = [
            ['Video 1', ContentType::Video, 'https://example.com/video1.mp4'],
            ['Video 2', ContentType::Video, 'https://example.com/video2.mp4'],
            ['Image 1', ContentType::Image, 'https://example.com/image1.jpg'],
            ['Text 1', ContentType::Text, null],
            ['Video 3', ContentType::Video, 'https://example.com/video3.mp4'],
        ];

        $contents = [];

        foreach ($definitions as [$title, $type, $url]) {
            $content = Content::query()->firstOrCreate(['title' => $title], [
                'type' => $type,
                'source' => $url ? ContentSource::Url : ContentSource::None,
                'text' => $url ? null : "Hello from the Daily Videos demo campaign! 👋\n\nThis is {$title}.",
                'caption' => $url ? "{$title} — posted by the Daily Videos demo campaign." : null,
                'media_url' => $url,
                'status' => ContentStatus::Active,
                'notes' => $url ? 'Demo placeholder: replace the media URL with a real, publicly reachable file before activating.' : null,
            ]);
            $content->tags()->syncWithoutDetaching([$tag->id]);
            $contents[] = $content;
        }

        if (Campaign::query()->where('name', 'Daily Videos')->exists()) {
            return;
        }

        $tz = 'Asia/Karachi';
        $start = CarbonImmutable::now($tz)->addDay()->setTime(9, 0);

        $campaign = Campaign::create([
            'name' => 'Daily Videos',
            'description' => 'Demo: posts 5 items one-by-one every 30 minutes starting at 09:00, then completes.',
            'bot_id' => $bot->id,
            'destination_id' => $destination->id,
            'status' => CampaignStatus::Draft,
            'posting_order' => PostingOrder::Sequential,
            'trigger_type' => TriggerType::Interval,
            'start_at' => $start->utc(),
            'timezone' => $tz,
            'interval_seconds' => 1800,
            'repeat_mode' => RepeatMode::None,
            'failure_behavior' => FailureBehavior::RetryThenContinue,
            'max_retries' => 3,
            'retry_delay_seconds' => 60,
        ]);

        foreach ($contents as $index => $content) {
            $campaign->campaignItems()->create([
                'content_id' => $content->id,
                'sort_order' => $index + 1,
                'status' => ItemStatus::Pending,
            ]);
        }
    }
}
