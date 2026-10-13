<?php

use App\Services\Telegram\TelegramPublisher;

return [

    /*
    |--------------------------------------------------------------------------
    | Publishers
    |--------------------------------------------------------------------------
    |
    | Maps a platform key to the class implementing App\Contracts\Publisher.
    | Register additional platforms here when they are implemented.
    |
    */

    'publishers' => [
        'telegram' => TelegramPublisher::class,
    ],

];
