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

    /** @var list<string> */
    public const STEPS = ['connecting', 'bangla', 'english'];

    public function edit(Request $request, ?string $step = null): Response|RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $step ??= 'connecting';

        abort_unless(in_array($step, self::STEPS, true), 404);

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

        $steps = $this->presentSteps($config);

        if (! $steps[$step]['unlocked']) {
            return redirect()->route('settings.postsyncer.edit', [
                'step' => $this->firstLockedFallback($steps, $step),
            ]);
        }

        return Inertia::render('workspace-settings/postsyncer', [
            'step' => $step,
            'steps' => $steps,
            'apiKeyConfigured' => $config->isConfigured(),
            'apiBase' => $config->apiBase(),
            'uploadBase' => $config->uploadBase(),
            'publishEnabled' => $config->publishEnabled(),
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

    public function update(
        UpdatePostsyncerSettingsRequest $request,
        UpdatePostsyncerSettingsAction $updatePostsyncerSettingsAction,
    ): RedirectResponse {
        $workspace = $this->currentWorkspace();

        $payload = $request->validated();
        unset($payload['step']);

        $updatePostsyncerSettingsAction->handle($workspace, $payload);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('PostSyncer settings saved.')]);

        $step = $request->input('step', 'connecting');
        $step = is_string($step) && in_array($step, self::STEPS, true)
            ? $step
            : 'connecting';

        return to_route('settings.postsyncer.edit', ['step' => $step]);
    }

    public function refreshAccounts(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
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

        $existing = $langConfig['platforms'];
        $suggested = $this->mergeAccountsByPlatform($existing, $accounts);

        return response()->json([
            'language' => $validated['language'],
            'suggested' => $suggested,
        ]);
    }

    /**
     * @return array{connecting: array{unlocked: bool, done: bool}, bangla: array{unlocked: bool, done: bool}, english: array{unlocked: bool, done: bool}}
     */
    private function presentSteps(PostsyncerConfig $config): array
    {
        $banglaReady = $config->language('bangla')['workspace_id'] !== null;
        $englishReady = $config->language('english')['workspace_id'] !== null;

        return [
            'connecting' => [
                'unlocked' => true,
                'done' => $config->isConfigured(),
            ],
            'bangla' => [
                'unlocked' => $config->isConfigured(),
                'done' => $banglaReady,
            ],
            'english' => [
                'unlocked' => $banglaReady,
                'done' => $englishReady,
            ],
        ];
    }

    /**
     * @param  array<string, array{unlocked: bool, done: bool}>  $steps
     */
    private function firstLockedFallback(array $steps, string $requested): string
    {
        $fallback = 'connecting';

        foreach (self::STEPS as $step) {
            if ($steps[$step]['unlocked']) {
                $fallback = $step;
            }

            if ($step === $requested) {
                break;
            }
        }

        return $fallback;
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
