<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Notifications\QueueTelegramNotificationAction;
use App\Actions\Notifications\TelegramNotificationKeyConflict;
use App\Actions\Notifications\TelegramNotificationsUnavailable;
use App\Data\Notifications\CreateTelegramNotificationData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreTelegramNotificationRequest;
use App\Models\TelegramBotConfig;
use App\Models\TelegramOutboundMessage;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Workspace-token notification endpoints backed by Telegram's durable outbox. */
class NotificationsApiController extends Controller
{
    public function store(
        StoreTelegramNotificationRequest $request,
        QueueTelegramNotificationAction $action,
    ): JsonResponse {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403, 'This token has no owning user.');

        try {
            $result = $action->handle(
                $this->currentWorkspace(),
                $owner,
                CreateTelegramNotificationData::fromRequest($request),
            );
        } catch (AuthorizationException) {
            abort(403, 'The token owner is not a member of this workspace.');
        } catch (TelegramNotificationKeyConflict) {
            abort(409, 'The notification key was already used with different content or recipient.');
        } catch (TelegramNotificationsUnavailable) {
            abort(409, 'Telegram notifications are not available for this workspace.');
        }

        return response()->json(
            $this->notificationPayload($request->string('key')->toString(), $result['message']),
            $result['created'] ? 202 : 200,
        );
    }

    public function show(Request $request, string $key): JsonResponse
    {
        $owner = $request->user();
        abort_unless($owner instanceof User, 403, 'This token has no owning user.');

        $workspace = $this->currentWorkspace();
        abort_unless($this->isWorkspaceMember($workspace, $owner), 403, 'The token owner is not a member of this workspace.');

        $config = TelegramBotConfig::query()->where('workspace_id', $workspace->id)->first();
        abort_if($config === null, 404);

        $message = TelegramOutboundMessage::query()
            ->where('telegram_bot_config_id', $config->id)
            ->where('logical_key', "api-notification:user:{$owner->id}:{$key}")
            ->first();

        abort_if($message === null, 404);

        return response()->json($this->notificationPayload($key, $message));
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();
        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }

    private function isWorkspaceMember(Workspace $workspace, User $owner): bool
    {
        return DB::table('team_user')
            ->where('team_id', $workspace->team_id)
            ->where('user_id', $owner->id)
            ->exists();
    }

    /**
     * Never expose the internal chat id, bot credentials, or dispatcher error.
     *
     * @return array{key: string, status: string, created_at: string|null, sent_at: string|null}
     */
    private function notificationPayload(string $key, TelegramOutboundMessage $message): array
    {
        return [
            'key' => $key,
            'status' => $message->status,
            'created_at' => $message->created_at?->toIso8601String(),
            'sent_at' => $message->sent_at?->toIso8601String(),
        ];
    }
}
