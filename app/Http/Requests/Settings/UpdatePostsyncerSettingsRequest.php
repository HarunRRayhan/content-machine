<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePostsyncerSettingsRequest extends FormRequest
{
    /** @var list<string> */
    public const PLATFORMS = [
        'facebook',
        'instagram',
        'twitter',
        'threads',
        'bluesky',
        'tiktok',
        'linkedin',
        'youtube',
    ];

    /** @var list<string> */
    public const POST_TYPES = ['text', 'photo', 'carousel', 'reel', 'thread'];

    /** @var list<string> */
    public const POST_TYPE_STATES = ['on', 'off', 'ask', 'unsupported'];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $workspace = Workspace::current();

        if ($workspace === null) {
            return false;
        }

        $member = $workspace->team->members()->whereKey($user->id)->first();

        return in_array($member?->pivot->role, ['owner', 'admin'], true);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $platformRules = [];
        foreach (self::PLATFORMS as $platform) {
            $platformRules["languages.bangla.platforms.{$platform}.account_id"] = ['nullable'];
            $platformRules["languages.bangla.platforms.{$platform}.handle"] = ['nullable', 'string', 'max:255'];
            $platformRules["languages.english.platforms.{$platform}.account_id"] = ['nullable'];
            $platformRules["languages.english.platforms.{$platform}.handle"] = ['nullable', 'string', 'max:255'];
        }

        $postTypeRules = [];
        foreach (self::PLATFORMS as $platform) {
            foreach (self::POST_TYPES as $type) {
                $postTypeRules["post_types.platforms.{$platform}.{$type}"] = [
                    'nullable',
                    Rule::in(self::POST_TYPE_STATES),
                ];
                $postTypeRules["post_types.overrides.bangla.{$platform}.{$type}"] = [
                    'nullable',
                    Rule::in(self::POST_TYPE_STATES),
                ];
                $postTypeRules["post_types.overrides.english.{$platform}.{$type}"] = [
                    'nullable',
                    Rule::in(self::POST_TYPE_STATES),
                ];
            }
        }

        return array_merge([
            'page' => ['nullable', Rule::in(['api', 'workspaces'])],
            'api_key' => ['nullable', 'string', 'max:500'],
            'api_base' => ['nullable', 'string', 'max:500'],
            'upload_base' => ['nullable', 'string', 'max:500'],
            'publish_enabled' => ['boolean'],
            'default_language' => ['nullable', Rule::in(PostsyncerConfig::LANGUAGES)],
            'enabled_languages' => ['nullable', 'array'],
            'enabled_languages.*' => ['string', Rule::in(PostsyncerConfig::LANGUAGES)],
            'languages.bangla.workspace_id' => ['nullable', 'string', 'max:100'],
            'languages.english.workspace_id' => ['nullable', 'string', 'max:100'],
        ], $platformRules, $postTypeRules);
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        $validated = parent::validated($key, $default);

        if (! is_array($validated)) {
            return [];
        }

        if (array_key_exists('publish_enabled', $validated)) {
            $validated['publish_enabled'] = (bool) $validated['publish_enabled'];
        }

        return $validated;
    }
}
