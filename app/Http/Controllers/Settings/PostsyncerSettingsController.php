<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Postsyncer\UpdatePostsyncerSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerClient;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Postsyncer\PostsyncerException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PostsyncerSettingsController extends Controller
{
    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace($request);
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        return Inertia::render('settings/postsyncer', [
            'apiKeyConfigured' => $config->isConfigured(),
            'apiBase' => $config->apiBase(),
            'uploadBase' => $config->uploadBase(),
            'publishEnabled' => $config->publishEnabled(),
            'languages' => [
                'bangla' => $this->presentLanguage($config, 'bangla'),
                'english' => $this->presentLanguage($config, 'english'),
            ],
            'postTypes' => $config->postTypes(),
            'platforms' => UpdatePostsyncerSettingsRequest::PLATFORMS,
            'postTypeNames' => UpdatePostsyncerSettingsRequest::POST_TYPES,
            'postTypeStates' => UpdatePostsyncerSettingsRequest::POST_TYPE_STATES,
        ]);
    }

    public function update(
        UpdatePostsyncerSettingsRequest $request,
        UpdatePostsyncerSettingsAction $updatePostsyncerSettingsAction,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace($request);

        $updatePostsyncerSettingsAction->handle($workspace, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('PostSyncer settings saved.')]);

        return to_route('dashboard.postsyncer.edit');
    }

    public function refreshAccounts(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace($request);
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $validated = $request->validate([
            'language' => ['required', Rule::in(['bangla', 'english'])],
        ]);

        $config = PostsyncerConfig::fromWorkspace($workspace);
        $langConfig = $config->language($validated['language']);
        $workspaceId = $langConfig['workspace_id'];

        if (! is_string($workspaceId) || $workspaceId === '') {
            return response()->json([
                'message' => 'Set a PostSyncer workspace id for this language first.',
            ], 422);
        }

        if (! $config->isConfigured()) {
            return response()->json([
                'message' => 'PostSyncer API key is not configured.',
            ], 422);
        }

        try {
            $accounts = (new PostsyncerClient($config))->listAccounts($workspaceId);
        } catch (PostsyncerException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $existing = is_array($langConfig['platforms']) ? $langConfig['platforms'] : [];
        $suggested = $this->mergeAccountsByPlatform($existing, $accounts);

        return response()->json([
            'language' => $validated['language'],
            'suggested' => $suggested,
        ]);
    }

    private function currentWorkspace(Request $request): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }

    private function authorizeWorkspaceAdmin(Request $request, Workspace $workspace): void
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        $member = $workspace->team->members()->whereKey($user->id)->first();

        abort_unless(in_array($member?->pivot->role, ['owner', 'admin'], true), 403);
    }

    /**
     * @return array{workspace_id: string|null, platforms: array<string, mixed>}
     */
    private function presentLanguage(PostsyncerConfig $config, string $language): array
    {
        $lang = $config->language($language);
        $platforms = is_array($lang['platforms']) ? $lang['platforms'] : [];

        $presented = [];
        foreach (UpdatePostsyncerSettingsRequest::PLATFORMS as $platform) {
            $entry = is_array($platforms[$platform] ?? null) ? $platforms[$platform] : [];
            $presented[$platform] = [
                'account_id' => $entry['account_id'] ?? null,
                'handle' => is_string($entry['handle'] ?? null) ? $entry['handle'] : '',
            ];
        }

        return [
            'workspace_id' => $lang['workspace_id'],
            'platforms' => $presented,
        ];
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, array{account_id: int|string|null, handle: string}>
     */
    private function mergeAccountsByPlatform(array $existing, array $accounts): array
    {
        $suggested = [];

        foreach (UpdatePostsyncerSettingsRequest::PLATFORMS as $platform) {
            $current = is_array($existing[$platform] ?? null) ? $existing[$platform] : [];
            $suggested[$platform] = [
                'account_id' => $current['account_id'] ?? null,
                'handle' => is_string($current['handle'] ?? null) ? $current['handle'] : '',
            ];
        }

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $platform = strtolower((string) ($account['platform'] ?? ''));

            if ($platform === '' || ! array_key_exists($platform, $suggested)) {
                continue;
            }

            $username = $account['username'] ?? null;
            $handle = is_string($username) && $username !== ''
                ? (str_starts_with($username, '@') ? $username : '@'.$username)
                : $suggested[$platform]['handle'];

            $suggested[$platform] = [
                'account_id' => $account['id'] ?? $suggested[$platform]['account_id'],
                'handle' => $handle,
            ];
        }

        return $suggested;
    }
}
