<?php

namespace App\Support\Content;

final class PresentationManifest
{
    /**
     * A manifest is usable only when the imported deck JavaScript can be
     * rendered by the presentation route and editor.
     *
     * @param  array<string, mixed>|null  $manifest
     */
    public static function isUsable(?array $manifest): bool
    {
        return is_string($manifest['js'] ?? null) && trim($manifest['js']) !== '';
    }
}
