<?php

namespace App\Http\Requests\Posts;

use App\Http\Requests\Settings\UpdatePostsyncerSettingsRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Postsyncer\PostsyncerConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RepairPostAccountMappingRequest extends FormRequest
{
    /**
     * The repair rewrites the workspace's PostSyncer account mapping, so it
     * needs the same owner/admin role as the PostSyncer settings page.
     */
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
