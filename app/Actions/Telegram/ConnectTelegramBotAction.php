<?php

namespace App\Actions\Telegram;

use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\TelegramPostRequest;
use App\Models\TelegramUpdate;
use App\Models\Workspace;
use App\Support\Telegram\TelegramBotCommands;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Validates a bot token against Telegram's own API, then registers this
 * app's webhook with Telegram, before ever storing anything: "configured"
 * and "enabled" are the same fact (TelegramBotConfig::isConnected()), so a
 * token that authenticates but whose webhook Telegram refuses to register
 * is never saved half-connected either. webhook_secret/webhook_slug are
 * generated on the workspace's first successful connect and rotated whenever
 * the bot token changes, so queued updates from an old token cannot be
 * processed with the replacement token. The command
 * menu (setMyCommands) is registered last and best-effort: unlike the
 * webhook, a failure here doesn't block the connection, it only means
 * Telegram's own "/" UI won't suggest commands yet.
 *
 * @throws RuntimeException if Telegram rejects the token or the webhook registration
 */
class ConnectTelegramBotAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(Workspace $workspace, ConnectTelegramBotData $data): TelegramBotConfig
    {
        $getMeResult = $this->client->getMe($data->botToken);

        if (! $getMeResult->successful) {
            throw new RuntimeException((string) $getMeResult->error);
        }

        return DB::transaction(function () use ($workspace, $data, $getMeResult): TelegramBotConfig {
            // Connect and disconnect must share this lock. Locking the parent
            // workspace also serializes the first connect, when no config row
            // exists yet and there is nothing else to lock.
            Workspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            $config = TelegramBotConfig::query()
                ->where('workspace_id', $workspace->id)
                ->lockForUpdate()
                ->first();
            $config ??= new TelegramBotConfig(['workspace_id' => $workspace->id]);

            $previousToken = $config->bot_token;
            $rotateWebhookIdentity = $config->webhook_secret !== null
                && ($previousToken === null || $previousToken !== $data->botToken);
            $webhookSecret = $rotateWebhookIdentity || $config->webhook_secret === null
                ? Str::random(40)
                : $config->webhook_secret;
            $webhookSlug = $rotateWebhookIdentity || $config->webhook_slug === null
                ? Str::random(40)
                : $config->webhook_slug;
            $webhookGeneration = $rotateWebhookIdentity || $config->webhook_generation === null
                ? (string) Str::uuid()
                : $config->webhook_generation;

            $webhookUrl = URL::route('telegram.webhook', ['slug' => $webhookSlug]);
            $setWebhookResult = $this->client->setWebhook($data->botToken, $webhookUrl, $webhookSecret);

            if (! $setWebhookResult->successful) {
                throw new RuntimeException((string) $setWebhookResult->error);
            }

            if ($rotateWebhookIdentity) {
                // Retire work accepted by the old identity before the new
                // identity becomes current. Keep the rows for reconciliation;
                // deleting them would make an already-acknowledged delivery
                // indistinguishable from one Telegram never sent.
                TelegramUpdate::query()
                    ->where('telegram_bot_config_id', $config->id)
                    ->whereNull('processed_at')
                    ->whereNull('failed_at')
                    ->whereNull('discarded_at')
                    ->update([
                        'processed_at' => now(),
                        'discarded_at' => now(),
                        'last_error' => 'The Telegram bot connection changed before this update was processed.',
                        'dispatch_claimed_at' => null,
                        'dispatch_lease_id' => null,
                        'updated_at' => now(),
                    ]);

                TelegramPostRequest::query()
                    ->where('telegram_bot_config_id', $config->id)
                    ->where('state', TelegramPostRequest::GENERATING)
                    ->update([
                        'state' => TelegramPostRequest::CANCELLED,
                        'cancelled_at' => now(),
                        'error_message' => 'The Telegram bot connection changed before this draft was generated.',
                        'work_claimed_at' => null,
                        'work_lease_id' => null,
                        'updated_at' => now(),
                    ]);

                TelegramOutboundMessage::query()
                    ->where('telegram_bot_config_id', $config->id)
                    ->where('status', TelegramOutboundMessage::PENDING)
                    ->update([
                        'status' => TelegramOutboundMessage::DISCARDED,
                        'discarded_at' => now(),
                        'dispatch_claimed_at' => null,
                        'dispatch_lease_id' => null,
                        'last_error' => 'The Telegram bot connection changed before this message was sent.',
                        'updated_at' => now(),
                    ]);
            }

            $config->fill([
                'workspace_id' => $workspace->id,
                'bot_token' => $data->botToken,
                'bot_username' => $getMeResult->username,
                'connected_at' => now(),
                'webhook_secret' => $webhookSecret,
                'webhook_slug' => $webhookSlug,
                'webhook_generation' => $webhookGeneration,
            ]);
            $config->save();

            if ($rotateWebhookIdentity && is_string($previousToken)) {
                try {
                    $this->client->deleteWebhook($previousToken);
                } catch (Throwable $exception) {
                    // The new slug/secret is already active, so an old
                    // webhook cannot reach this config even if Telegram is
                    // unavailable.
                    report($exception);
                }
            }

            try {
                $this->client->setMyCommands($data->botToken, TelegramBotCommands::LIST);
            } catch (Throwable $exception) {
                // The command menu is optional; the connected bot still
                // accepts these commands as ordinary messages.
                report($exception);
            }

            return $config;
        });
    }
}
