<?php

namespace App\Http\Requests\Posts;

use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepairPostAccountMappingRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>|string>
     */
    public function rules(): array
    {
        return [
            'language' => ['required', Rule::in(PostsyncerConfig::LANGUAGES)],
            'platform' => ['required', 'string', Rule::in(UpdatePostsyncerSettingsRequest::PLATFORMS)],
            'from_account_id' => ['required', 'integer', 'min:1'],
            'to_account_id' => ['required', 'integer', 'min:1', 'different:from_account_id'],
        ];
    }
}
