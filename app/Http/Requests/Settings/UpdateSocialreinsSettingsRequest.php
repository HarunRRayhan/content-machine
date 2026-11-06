<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use App\Support\Socialreins\SocialreinsConfig;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSocialreinsSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $workspace = Workspace::current();

        if (! $user instanceof User || $workspace === null) {
            return false;
        }

        $member = $workspace->team->members()->whereKey($user->id)->first();

        return in_array($member?->pivot->role, ['owner', 'admin'], true);
    }

    /** @return array<string, ValidationRule|array<mixed>|string> */
    public function rules(): array
    {
        $rules = [
            'publish_driver' => ['required', Rule::in(['postsyncer', SocialreinsConfig::DRIVER])],
            'base_url' => ['required', 'url:https,http', 'max:255'],
            'webhook_secret' => ['nullable', 'string', 'max:255'],
            'tiktok_privacy_level' => ['nullable', Rule::in(SocialreinsConfig::PRIVACY_LEVELS)],
            'default_language' => ['required', Rule::in(PostsyncerConfig::LANGUAGES)],
        ];

        foreach (PostsyncerConfig::LANGUAGES as $language) {
            $rules["languages.{$language}.set_id"] = ['nullable', 'uuid'];
            $rules["languages.{$language}.token"] = ['nullable', 'string', 'max:255'];
        }

        return $rules;
    }
}
