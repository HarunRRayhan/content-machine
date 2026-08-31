<?php

namespace App\Http\Controllers\Media;

use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Workspace;
use App\Support\Media\PostDesignTemplate;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PostDesignTemplatesController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);

        $counts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->whereNotNull('template')
            ->selectRaw('template, count(*) as aggregate')
            ->groupBy('template')
            ->pluck('aggregate', 'template');

        $templates = array_map(static function (PostDesignTemplate $template) use ($counts) {
            return [
                ...$template->toArray(),
                'post_count' => (int) ($counts[$template->letter] ?? 0),
            ];
        }, PostDesignTemplate::all());

        return Inertia::render('media/templates/index', [
            'templates' => $templates,
        ]);
    }

    public function show(Request $request, string $letter): Response
    {
        $workspace = $this->currentWorkspace($request);
        $template = PostDesignTemplate::tryFrom($letter);
        abort_if($template === null, 404);

        $posts = Post::query()
            ->where('workspace_id', $workspace->id)
            ->where('template', $template->letter)
            ->orderByDesc('number')
            ->get(['id', 'human_id', 'number', 'title', 'status', 'updated_at'])
            ->map(static fn (Post $post) => [
                'id' => $post->id,
                'human_id' => $post->human_id,
                'number' => $post->number,
                'title' => $post->title,
                'status' => $post->status,
                'updated_at' => $post->updated_at?->toIso8601String(),
            ])
            ->all();

        return Inertia::render('media/templates/show', [
            'template' => $template->toArray(),
            'posts' => $posts,
        ]);
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
