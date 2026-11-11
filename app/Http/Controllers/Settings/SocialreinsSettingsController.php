<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Socialreins\SaveSocialreinsSettingsAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesWorkspaceSettings;
use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use App\Http\Requests\Settings\UpdateSocialreinsSettingsRequest;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SocialreinsSettingsController extends Controller
{
    use AuthorizesWorkspaceSettings;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $config = SocialreinsConfig::fromWorkspace($workspace);

        $languages = [];
        foreach (PostsyncerConfig::LANGUAGES as $language) {
            $languages[$language] = [
                'set_id' => $config->setId($language),
                'token_configured' => $config->token($language) !== null,
                'platforms' => $config->coveredPlatforms($language) ?? UpdatePostsyncerSettingsRequest::PLATFORMS,
            ];
        }

        // Secrets are write-only: only whether they are set goes to the page.
        return Inertia::render('workspace-settings/socialreins', [
            'driver' => SocialreinsConfig::usesSocialreins($workspace) ? SocialreinsConfig::DRIVER : 'postsyncer',
            'baseUrl' => $config->baseUrl(),
            'webhookUrl' => route('socialreins.webhook', ['workspace' => $workspace->id]),
            'webhookSecretConfigured' => $config->webhookSecret() !== null,
            'tiktokPrivacyLevel' => $config->tiktokPrivacy(),
            'privacyLevels' => SocialreinsConfig::PRIVACY_LEVELS,
            'defaultLanguage' => $config->defaultLanguage(),
            'routedLanguages' => $config->routedLanguages(),
            'languages' => $languages,
            'platforms' => UpdatePostsyncerSettingsRequest::PLATFORMS,
        ]);
    }

    public function update(
        UpdateSocialreinsSettingsRequest $request,
        SaveSocialreinsSettingsAction $action,
    ): RedirectResponse {
        $action->handle($this->currentWorkspace(), $request->validated());

        return to_route('settings.socialreins.edit')->with('success', 'SocialReins settings saved.');
    }
}
