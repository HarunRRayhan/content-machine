<?php

namespace App\Rules;

use App\Support\GoogleDrive\GoogleDriveLinkChecker;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class AccessibleDriveUrl implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || trim($value) === '') {
            return;
        }

        $result = app(GoogleDriveLinkChecker::class)->check($value);

        if (! $result->ok) {
            $fail($result->message);
        }
    }
}
