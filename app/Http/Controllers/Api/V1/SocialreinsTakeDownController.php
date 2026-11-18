<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Socialreins\TakeDownSocialreinsAction;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** Takes a post or video published through SocialReins down from its accounts. */
class SocialreinsTakeDownController extends Controller
{
    public function post(string $humanId, TakeDownSocialreinsAction $action): JsonResponse
    {
        $workspace = $this->workspace();
        $post = Post::query()->where('workspace_id', $workspace->id)->whereLabel($humanId)->firstOrFail();

        return $this->run($post, $workspace, $action);
    }

    public function video(string $humanId, TakeDownSocialreinsAction $action): JsonResponse
    {
        $workspace = $this->workspace();
        $video = Video::query()->where('workspace_id', $workspace->id)->where('human_id', $humanId)->firstOrFail();

        return $this->run($video, $workspace, $action);
    }

    private function run(Post|Video $subject, Workspace $workspace, TakeDownSocialreinsAction $action): JsonResponse
    {
        if (! SocialreinsConfig::routesToSocialreins($subject, $workspace)) {
            throw ValidationException::withMessages(['take_down' => __('This post or video is not published through SocialReins.')]);
        }

        $problems = $action->handle($subject);

        // 422 like brain's own takedown_failed: nothing is hidden, and a retry only tries what is still up.
        return response()->json(
            ['taken_down' => $problems === [], 'problems' => $problems],
            $problems === [] ? 200 : 422,
        );
    }

    private function workspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
