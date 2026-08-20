<?php

namespace App\Http\Controllers\Telegram;

use App\Actions\Telegram\ConnectTelegramBotAction;
use App\Actions\Telegram\DisconnectTelegramBotAction;
use App\Data\Telegram\ConnectTelegramBotData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Telegram\StoreTelegramBotConfigRequest;
use App\Models\TelegramBotConfig;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class TelegramBotConfigController extends Controller
{
    /**
     * Show the current workspace's Telegram connection state. The token
     * itself is never returned, only whether one is set and, if so, the
     * bot username it validated against.
     */
    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        $config = $this->config($workspace);

        return Inertia::render('telegram/edit', [
            'connected' => $config?->isConnected() ?? false,
            'botUsername' => $config?->bot_username,
            'connectedAt' => $config?->connected_at?->toIso8601String(),
        ]);
    }

    public function update(StoreTelegramBotConfigRequest $request, ConnectTelegramBotAction $connectTelegramBotAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);

        try {
            $connectTelegramBotAction->handle($workspace, ConnectTelegramBotData::fromRequest($request));
        } catch (RuntimeException $e) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return to_route('dashboard.telegram.edit');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram bot connected.')]);

        return to_route('dashboard.telegram.edit');
    }

    public function destroy(Request $request, DisconnectTelegramBotAction $disconnectTelegramBotAction): RedirectResponse
    {
        $workspace = $this->currentWorkspace($request);
        $config = $this->config($workspace);

        if ($config !== null) {
            $disconnectTelegramBotAction->handle($config);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Telegram bot disconnected.')]);

        return to_route('dashboard.telegram.edit');
    }

    private function config(Workspace $workspace): ?TelegramBotConfig
    {
        return TelegramBotConfig::query()->where('workspace_id', $workspace->id)->first();
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
