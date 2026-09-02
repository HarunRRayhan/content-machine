<?php

namespace App\Http\Controllers\Videos;

use App\Actions\Videos\UpdatePresentationCueAction;
use App\Data\Videos\UpdatePresentationCueData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Videos\UpdatePresentationCueRequest;
use App\Models\Video;
use App\Models\Workspace;
use App\Support\Content\PresentationManifest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Fullscreen presentation player for a video's stored deck package.
 * Decks are imported into videos.deck_manifest as { engine, deck_key, css, js }.
 */
class VideoPresentationController extends Controller
{
    public function show(Request $request, Video $video): View|Response
    {
        $workspace = Workspace::current();
        abort_if($workspace === null, 404, 'No current workspace.');
        abort_if($video->workspace_id !== $workspace->id, 404);

        $manifest = $video->deck_manifest;
        if (! PresentationManifest::isUsable($manifest)) {
            abort(404, 'No presentation deck stored for this video.');
        }

        $embed = $request->boolean('embed');
        $theme = $request->string('theme')->toString();
        if (! in_array($theme, ['light', 'dark'], true)) {
            $theme = 'light';
        }

        return response()
            ->view('videos.presentation', [
                'video' => $video,
                'manifest' => $manifest,
                'embed' => $embed,
                'theme' => $theme,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    public function updateCue(
        UpdatePresentationCueRequest $request,
        Video $video,
        UpdatePresentationCueAction $updatePresentationCueAction,
    ): JsonResponse {
        $workspace = Workspace::current();
        abort_if($workspace === null, 404, 'No current workspace.');
        abort_if($video->workspace_id !== $workspace->id, 404);

        $data = UpdatePresentationCueData::fromRequest($request);

        try {
            $updatePresentationCueAction->handle($video, $data);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'ok' => false,
                'error' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'cue' => trim($data->cue),
        ]);
    }
}
