<?php

namespace App\Http\Controllers\Telegram;

use App\Actions\Telegram\GenerateTelegramLinkCodeAction;
use App\Actions\Telegram\SendTelegramTestMessageAction;
use App\Http\Controllers\Controller;
use App\Models\TelegramBotConfig;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use RuntimeException;

/**
 * The personal, per-user counterpart to TelegramBotConfigController (which
 * owns the workspace-level bot connection): generating a code to link the
 * logged-in user's own Telegram account, and sending that user a test
 * message once linked.
 */
class TelegramBotLinkController extends Controller
{
    public function store(Request $request, GenerateTelegramLinkCodeAction $generateTelegramLinkCodeAction): RedirectResponse
    {
        $config = $this->connectedConfig($request);

        $linkCode = $generateTelegramLinkCodeAction->handle($config, $this->currentUser($request));

        Inertia::flash('linkCode', [
            'code' => $linkCode->code,
            'expiresAt' => $linkCode->expires_at->toIso8601String(),
        ]);

        return to_route('settings.telegram.edit');
    }

    public function test(Request $request, SendTelegramTestMessageAction $sendTelegramTestMessageAction): RedirectResponse
    {
        $config = $this->connectedConfig($request);

        try {
            $sendTelegramTestMessageAction->handle($config, $this->currentUser($request));
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('settings.telegram.edit');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Test message queued, check Telegram.')]);

        return to_route('settings.telegram.edit');
    }

    private function currentUser(Request $request): User
    {
        $user = $request->user();

        abort_if(! $user instanceof User, 403);

        return $user;
    }

    private function connectedConfig(Request $request): TelegramBotConfig
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        $config = TelegramBotConfig::query()->where('workspace_id', $workspace->id)->first();

        abort_if($config === null || ! $config->isConnected(), 404, 'The Telegram bot is not connected.');

        return $config;
    }
}
