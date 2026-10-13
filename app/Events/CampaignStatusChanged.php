<?php

namespace App\Events;

use App\Enums\CampaignStatus;
use App\Models\Campaign;
use Illuminate\Foundation\Events\Dispatchable;

class CampaignStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Campaign $campaign,
        public CampaignStatus $from,
        public CampaignStatus $to,
    ) {}
}
