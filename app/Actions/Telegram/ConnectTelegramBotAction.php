<?php

namespace App\Actions\Telegram;

use App\Data\Telegram\ConnectTelegramBotData;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use App\Support\Telegram\TelegramClientContract;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Validates a bot token against Telegram's own API before ever storing it:
 * "configured" and "enabled" are the same fact
 * (TelegramBotConfig::isConnected()), so an unvalidated token is never
 * saved half-connected. webhook_secret/webhook_slug are generated once,
 * on the workspace's first successful connect, and kept stable across
 * later disconnect/reconnect cycles so its webhook URL doesn't change
 * every time the token is rotated.
 *
 * @throws RuntimeException if Telegram rejects the token
 */
class ConnectTelegramBotAction
{
    public function __construct(
        private readonly TelegramClientContract $client,
    ) {}

    public function handle(Workspace $workspace, ConnectTelegramBotData $data): TelegramBotConfig
    {
        $result = $this->client->getMe($data->botToken);

        if (! $result->successful) {
            throw new RuntimeException((string) $result->error);
        }

        $config = TelegramBotConfig::firstOrNew(['workspace_id' => $workspace->id]);

        $config->fill([
            'workspace_id' => $workspace->id,
            'bot_token' => $data->botToken,
            'bot_username' => $result->username,
            'connected_at' => now(),
            'webhook_secret' => $config->webhook_secret ?? Str::random(40),
            'webhook_slug' => $config->webhook_slug ?? Str::random(40),
        ]);

        $config->save();

        return $config;
    }
}
