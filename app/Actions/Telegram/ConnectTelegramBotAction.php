<?php

namespace App\Actions\Telegram;

use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validates a bot token against Telegram's own API, then registers this
 * app's webhook with Telegram, before ever storing anything: "configured"
 * and "enabled" are the same fact (TelegramBotConfig::isConnected()), so a
 * token that authenticates but whose webhook Telegram refuses to register
 * is never saved half-connected either. webhook_secret/webhook_slug are
 * generated once, on the workspace's first successful connect, and kept
 * stable across later disconnect/reconnect cycles so the registered
 * webhook URL doesn't change every time the token is rotated.
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

        $config = TelegramBotConfig::firstOrNew(['workspace_id' => $workspace->id]);
        $webhookSecret = $config->webhook_secret ?? Str::random(40);
        $webhookSlug = $config->webhook_slug ?? Str::random(40);

        $webhookUrl = URL::route('telegram.webhook', ['slug' => $webhookSlug]);
        $setWebhookResult = $this->client->setWebhook($data->botToken, $webhookUrl, $webhookSecret);

        if (! $setWebhookResult->successful) {
            throw new RuntimeException((string) $setWebhookResult->error);
        }

        $config->fill([
            'workspace_id' => $workspace->id,
            'bot_token' => $data->botToken,
            'bot_username' => $getMeResult->username,
            'connected_at' => now(),
            'webhook_secret' => $webhookSecret,
            'webhook_slug' => $webhookSlug,
        ]);

        $config->save();

        return $config;
    }
}
