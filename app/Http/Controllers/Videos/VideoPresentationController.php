<?php

namespace App\Http\Controllers\Videos;

use App\Http\Controllers\Controller;
use App\Models\Video;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

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
        if (! is_array($manifest) || empty($manifest['js'])) {
            abort(404, 'No presentation deck stored for this video.');
        }

        $embed = $request->boolean('embed');

        return response()
            ->view('videos.presentation', [
                'video' => $video,
                'manifest' => $manifest,
                'embed' => $embed,
            ])
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
