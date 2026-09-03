<?php

namespace App\Rules;

use App\Support\Content\PresentationManifest;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class RenderablePresentationManifest implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value !== null && (! is_array($value) || ! PresentationManifest::isUsable($value))) {
            $fail('The :attribute must contain a registered, renderable presentation deck.');
        }
    }
}
