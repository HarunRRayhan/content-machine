<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

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

        $rawUpdateId = $request->input('update_id');
        if ((! is_int($rawUpdateId) && ! (is_string($rawUpdateId) && ctype_digit($rawUpdateId)))
            || (int) $rawUpdateId < 0
        ) {
            abort(422, 'Invalid Telegram update id.');
        }

        $updateId = (int) $rawUpdateId;
        $payload = $request->all();

        /** @var int $inserted */
        [$config, $update, $inserted, $adopted] = DB::transaction(function () use ($config, $slug, $providedSecret, $updateId, $payload): array {
            // ConnectTelegramBotAction holds this row lock while replacing a
            // webhook identity and retiring its update fence. Re-check the
            // slug after waiting so an old delivery cannot be recorded during
            // rotation.
            $lockedConfig = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->lockForUpdate()
                ->firstOrFail();

            abort_if($lockedConfig->webhook_slug !== $slug, 404);
            abort_if(
                $lockedConfig->webhook_secret === null
                    || ! hash_equals($lockedConfig->webhook_secret, $providedSecret),
                403,
            );

            if (! $lockedConfig->isConnected()) {
                return [$lockedConfig, null, 0, false];
            }

            $inserted = DB::table('telegram_updates')->insertOrIgnore([
                'telegram_bot_config_id' => $lockedConfig->id,
                'webhook_generation' => $lockedConfig->webhook_generation,
                'update_id' => $updateId,
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $updateQuery = TelegramUpdate::query()
                ->where('telegram_bot_config_id', $lockedConfig->id)
                ->where('update_id', $updateId)
                ->when(
                    $lockedConfig->webhook_generation !== null,
                    fn ($query) => $query->where(function ($generationQuery) use ($lockedConfig): void {
                        $generationQuery
                            ->where('webhook_generation', $lockedConfig->webhook_generation)
                            ->orWhereNull('webhook_generation');
                    }),
                    fn ($query) => $query->whereNull('webhook_generation'),
                )
                ->orderByDesc('id')
                ->lockForUpdate();

            $update = $updateQuery->first();

            if ($update === null) {
                // During the expand phase the old update-id fence can reject
                // insertion before the generation-aware lookup finds a row.
                // Treat that older row as the durable duplicate fence.
                $update = TelegramUpdate::query()
                    ->where('telegram_bot_config_id', $lockedConfig->id)
                    ->where('update_id', $updateId)
                    ->orderByDesc('id')
                    ->lockForUpdate()
                    ->firstOrFail();
            }

            if ($update->payload === null) {
                $update->forceFill(['payload' => $payload])->save();
            }

            $adopted = false;
            if ($update->webhook_generation === null && $lockedConfig->webhook_generation !== null) {
                // An old web instance may have committed this row during the
                // expand phase. Adopt it under the identity that accepted the
                // current webhook instead of silently losing its update.
                $update->forceFill([
                    'webhook_generation' => $lockedConfig->webhook_generation,
                ])->save();
                $adopted = true;
            }

            return [$lockedConfig, $update, $inserted, $adopted];
        });

        if (! $config->isConnected()) {
            return response()->noContent();
        }

        // Only the delivery that created the outbox row dispatches immediately.
        // If that queue insertion fails after the row commits, the scheduled
        // drain retries it; duplicate Telegram deliveries cannot enqueue a
        // second copy while the first one is already pending.
        if (($inserted > 0 || $adopted)
            && $update instanceof TelegramUpdate
            && $update->processed_at === null
            && $update->failed_at === null
            && $update->discarded_at === null
        ) {
            ProcessTelegramUpdateJob::dispatch(
                $config->id,
                $update->payload ?? $payload,
                $update->webhook_generation,
            );
        }

        return response()->noContent();
    }
}
