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
 * webhook URL doesn't change every time the token is rotated. The command
 * menu (setMyCommands) is registered last and best-effort: unlike the
 * webhook, a failure here doesn't block the connection, it only means
 * Telegram's own "/" UI won't suggest commands yet.
 *
 * @throws RuntimeException if Telegram rejects the token or the webhook registration
 */
class ConnectTelegramBotAction
{
    /**
     * @var array<int, array{command: string, description: string}>
     */
    private const COMMANDS = [
        ['command' => 'start', 'description' => 'Get started'],
        ['command' => 'help', 'description' => 'See what I can do'],
        ['command' => 'me', 'description' => 'Which account you\'re linked as'],
        ['command' => 'link', 'description' => 'Link your account with a code'],
        ['command' => 'videos', 'description' => 'Your most recent videos'],
        ['command' => 'posts', 'description' => 'Your most recent posts'],
        ['command' => 'note', 'description' => 'Save a Scratch Pad note'],
    ];

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

        $this->client->setMyCommands($data->botToken, self::COMMANDS);

        return $config;
    }
}
