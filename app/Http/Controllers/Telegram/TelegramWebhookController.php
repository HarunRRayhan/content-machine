<?php

namespace App\Http\Controllers\Telegram;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Models\TelegramBotConfig;
use App\Models\TelegramUpdate;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

        // This bot exposes workspace data and actions, so only Telegram
        // private chats may reach the durable processing path. Ignore group,
        // supergroup, and channel messages without storing or acknowledging
        // them.
        $message = $payload['message'] ?? null;
        if (is_array($message)
            && (! is_array($message['chat'] ?? null) || ($message['chat']['type'] ?? null) !== 'private')
        ) {
            return response()->noContent();
        }

        /** @var int $inserted */
        [$config, $update, $inserted, $adopted, $recovered] = DB::transaction(function () use ($config, $slug, $providedSecret, $updateId, $payload): array {
            // ConnectTelegramBotAction holds the workspace/config locks while replacing a
            // webhook identity and retiring its update fence. Re-check the
            // slug after waiting so an old delivery cannot be recorded during
            // rotation.
            $configReference = TelegramBotConfig::query()
                ->whereKey($config->id)
                ->first(['workspace_id']);

            abort_if($configReference === null, 404);

            Workspace::query()
                ->whereKey($configReference->workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

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
                return [$lockedConfig, null, 0, false, false];
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

            $adopted = false;
            $recovered = false;
            $recoverableMissingPayload = $update->processed_at === null
                && $update->failed_at !== null
                && $update->last_error === ProcessTelegramUpdateJob::MISSING_PAYLOAD_ERROR
                && $update->payload === null
                && $update->discarded_at === null;

            if ($update->webhook_generation !== $lockedConfig->webhook_generation) {
                if (($update->processed_at !== null
                    || $update->failed_at !== null
                    || $update->discarded_at !== null)
                    && ! $recoverableMissingPayload
                ) {
                    // A terminal legacy row is an already-processed delivery,
                    // not a reusable row for a new generation. Keep it as the
                    // duplicate fence until the legacy index is removed.
                    return [$lockedConfig, $update, $inserted, false, false];
                }

                // Before the legacy global update-id index is removed, a
                // rotated bot can hit the old row even though this is a new
                // event for the new webhook identity. Reuse only an unfinished
                // row as the current-generation fence.
                $update->forceFill([
                    'webhook_generation' => $lockedConfig->webhook_generation,
                    'payload' => $payload,
                    'processed_at' => null,
                    'failed_at' => null,
                    'discarded_at' => null,
                    'last_error' => null,
                    'dispatch_claimed_at' => null,
                    'dispatch_lease_id' => null,
                ])->save();
                $adopted = true;
            } elseif ($update->payload === null) {
                $update->forceFill([
                    'payload' => $payload,
                    ...($recoverableMissingPayload ? [
                        'failed_at' => null,
                        'last_error' => null,
                        'dispatch_claimed_at' => null,
                        'dispatch_lease_id' => null,
                    ] : []),
                ])->save();
                $recovered = $recoverableMissingPayload;
            }

            return [$lockedConfig, $update, $inserted, $adopted, $recovered];
        });

        if (! $config->isConnected()) {
            return response()->noContent();
        }

        // Only the delivery that created the outbox row dispatches immediately.
        // If that queue insertion fails after the row commits, the scheduled
        // drain retries it; duplicate Telegram deliveries cannot enqueue a
        // second copy while the first one is already pending.
        if (($inserted > 0 || $adopted || $recovered)
            && $update instanceof TelegramUpdate
            && $update->processed_at === null
            && $update->failed_at === null
            && $update->discarded_at === null
        ) {
            try {
                ProcessTelegramUpdateJob::dispatch(
                    $config->id,
                    $update->payload ?? $payload,
                    $update->webhook_generation,
                );
            } catch (\Throwable $exception) {
                // The durable update row is the recovery source of truth. Do
                // not make Telegram retry a request just because queue
                // insertion is temporarily unavailable.
                Log::error('Telegram update job dispatch failed', [
                    'telegram_bot_config_id' => $config->id,
                    'telegram_update_id' => $update->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        return response()->noContent();
    }
}
