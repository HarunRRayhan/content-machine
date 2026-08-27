<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Postsyncer\UpdatePostsyncerSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesWorkspaceSettings;
use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
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
    use AuthorizesWorkspaceSettings;

    public function edit(Request $request): Response
    {
        return $this->page($request, 'workspace-settings/postsyncer-api');
    }

    public function workspaces(Request $request): Response|RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $config = PostsyncerConfig::fromWorkspace($workspace);

        if (! $config->isConfigured()) {
            return redirect()->route('settings.postsyncer.edit');
        }

        return $this->page($request, 'workspace-settings/postsyncer-workspaces');
    }

    public function update(
        UpdatePostsyncerSettingsRequest $request,
        UpdatePostsyncerSettingsAction $updatePostsyncerSettingsAction,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace();

        $payload = $request->validated();
        unset($payload['page']);

        $updatePostsyncerSettingsAction->handle($workspace, $payload);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('PostSyncer settings saved.')]);

        $page = $request->input('page', 'api');

        if ($page === 'workspaces') {
            return to_route('settings.postsyncer.workspaces');
        }

        return to_route('settings.postsyncer.edit');
    }

    public function refreshAccounts(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $validated = $request->validate([
            'language' => ['required', Rule::in(PostsyncerConfig::LANGUAGES)],
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

        $existing = $langConfig['platforms'];
        $suggested = $this->mergeAccountsByPlatform($existing, $accounts);

        return response()->json([
            'language' => $validated['language'],
            'suggested' => $suggested,
        ]);
    }

    private function page(Request $request, string $component): Response
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $config = PostsyncerConfig::fromWorkspace($workspace);
        $availableWorkspaces = [];
        $workspacesLoadError = null;

        if ($config->isConfigured()) {
            try {
                $availableWorkspaces = (new PostsyncerClient($config))->listWorkspaces();
            } catch (PostsyncerException $e) {
                $workspacesLoadError = $e->getMessage();
            }
        }

        return Inertia::render($component, [
            'apiKeyConfigured' => $config->isConfigured(),
            'apiBase' => $config->apiBase(),
            'uploadBase' => $config->uploadBase(),
            'publishEnabled' => $config->publishEnabled(),
            'defaultLanguage' => $config->defaultLanguage(),
            'enabledLanguages' => $config->enabledLanguages(),
            'availableWorkspaces' => $availableWorkspaces,
            'workspacesLoadError' => $workspacesLoadError,
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

    /**
     * @return array{workspace_id: string|null, platforms: array<string, mixed>}
     */
    private function presentLanguage(PostsyncerConfig $config, string $language): array
    {
        $lang = $config->language($language);
        $platforms = $lang['platforms'];

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
     * @param  array<int, array<string, mixed>>  $accounts
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
