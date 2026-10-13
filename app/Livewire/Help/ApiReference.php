<?php

namespace App\Livewire\Help;

use App\Enums\CampaignStatus;
use App\Enums\ConnectionStatus;
use App\Enums\ContentSource;
use App\Enums\ContentType;
use App\Enums\DestinationType;
use App\Enums\FailureBehavior;
use App\Enums\PostingStatus;
use App\Enums\RepeatMode;
use App\Enums\TriggerType;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * REST API reference, generated against the live base URL.
 */
#[Title('API Docs')]
class ApiReference extends Component
{
    public const SECTIONS = [
        'overview' => 'Overview',
        'authentication' => 'Authentication',
        'conventions' => 'Requests, errors, limits',
        'endpoints' => 'Endpoint list',
        'auth-endpoints' => 'Auth',
        'bots' => 'Bots',
        'destinations' => 'Destinations',
        'contents' => 'Contents',
        'campaigns' => 'Campaigns',
        'campaign-actions' => 'Campaign actions',
        'logs' => 'Posting logs',
        'enums' => 'Allowed values',
        'example-flow' => 'Complete example',
    ];

    public function render()
    {
        // Allowed values are read from the enums, so this page never drifts from the code.
        $enums = [
            'Content type (type)' => ContentType::values(),
            'Content source (source)' => ContentSource::values(),
            'Destination type (type)' => DestinationType::values(),
            'Campaign status (status)' => CampaignStatus::values(),
            'Trigger (trigger_type)' => TriggerType::values(),
            'Repeat (repeat_mode)' => RepeatMode::values(),
            'On failure (failure_behavior)' => FailureBehavior::values(),
            'Posting log status' => PostingStatus::values(),
            'Connection status' => ConnectionStatus::values(),
            'Bot / user status' => ['active', 'disabled'],
            'Destination / content status' => ['active', 'inactive'],
            'Posting order (posting_order)' => ['sequential', 'random'],
            'Formatting (parse_mode)' => ['HTML', 'MarkdownV2'],
        ];

        return view('livewire.help.api-reference', [
            'sections' => self::SECTIONS,
            'base' => url('/api'),
            'enums' => $enums,
        ]);
    }
}
