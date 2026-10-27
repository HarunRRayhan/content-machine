<?php

namespace App\Http\Requests\Settings;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePexelsSettingsRequest extends FormRequest
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

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['api_key' => ['required', 'string', 'min:1', 'max:500']];
    }
}
