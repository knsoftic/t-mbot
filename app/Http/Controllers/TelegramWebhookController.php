<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives bot updates from Telegram (welcome messages). Answers at once and
 * leaves the work to the queue.
 */
class TelegramWebhookController extends Controller
{
    public function __invoke(Request $request, int $bot): JsonResponse
    {
        $model = TelegramBot::query()->withoutGlobalScopes()->find($bot);
        $secret = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        if (! $model || ! $model->webhook_secret || ! hash_equals($model->webhook_secret, $secret)) {
            return response()->json(['ok' => false], 403);
        }

        $update = $request->json()->all();

        if (! isset($update['update_id'])) {
            return response()->json(['ok' => true]);
        }

        // Keep only what is used; group chatter is never queued.
        $kept = array_intersect_key($update, array_flip(['update_id', 'chat_member', 'chat_join_request', 'message']));

        if (isset($kept['message']) && ($kept['message']['chat']['type'] ?? null) !== 'private') {
            unset($kept['message']);
        }

        if (count($kept) > 1) {
            ProcessTelegramUpdateJob::dispatch($model->id, $kept);
        }

        return response()->json(['ok' => true]);
    }
}
