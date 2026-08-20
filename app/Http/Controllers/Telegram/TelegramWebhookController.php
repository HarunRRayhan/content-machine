<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Receives Telegram's webhook deliveries. Per
 * docs/adr/0001-webhook-not-polling.md: the unguessable {slug} path
 * segment identifies which workspace's bot this is, and the
 * X-Telegram-Bot-Api-Secret-Token header (compared with hash_equals, not
 * ===) proves the request actually came from Telegram, not from someone
 * who merely guessed or observed the URL. Every branch returns fast and
 * does no real work inline, dispatching a queued job instead: Telegram
 * retries aggressively on anything but a fast 2xx (ADR 0001 again), so a
 * slow handler here becomes a self-inflicted retry storm.
 */
class TelegramWebhookController extends Controller
{
    public function handle(Request $request, string $slug): Response
    {
        $config = TelegramBotConfig::query()->where('webhook_slug', $slug)->first();

        abort_if($config === null, 404);

        $providedSecret = $request->header('X-Telegram-Bot-Api-Secret-Token');

        abort_if(
            $config->webhook_secret === null || ! is_string($providedSecret) || ! hash_equals($config->webhook_secret, $providedSecret),
            403,
        );

        // Disconnected (or never fully connected): accept so Telegram
        // doesn't retry, but there's nothing to record or process.
        if (! $config->isConnected()) {
            return response()->noContent();
        }

        $updateId = $request->integer('update_id');

        $update = TelegramUpdate::firstOrCreate([
            'telegram_bot_config_id' => $config->id,
            'update_id' => $updateId,
        ]);

        // Telegram redelivers when it doesn't see a fast 2xx; only the
        // first sighting of a given update_id gets processed.
        if ($update->wasRecentlyCreated) {
            ProcessTelegramUpdateJob::dispatch($config->id, $request->all());
        }

        return response()->noContent();
    }
}
