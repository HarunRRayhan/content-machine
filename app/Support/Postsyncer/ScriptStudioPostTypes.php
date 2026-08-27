<?php

namespace App\Support\Postsyncer;

use JsonException;
use RuntimeException;

/**
 * Script Studio's post-type matrix, bundled so CM can pre-fill Settings
 * without a live copy of personal-content.
 */
final class ScriptStudioPostTypes
{
    /**
     * @return array{platforms: array<string, array<string, string|null>>, overrides: array<string, array<string, array<string, string|null>>>}
     */
    public static function defaults(): array
    {
        $path = resource_path('data/postsyncer/post_types.json');
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException("Could not read {$path}");
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Invalid Script Studio post types: '.$exception->getMessage(), 0, $exception);
        }

        $decoded = is_array($decoded) ? $decoded : [];
        $platforms = is_array($decoded['platforms'] ?? null) ? $decoded['platforms'] : [];
        $overrides = is_array($decoded['overrides'] ?? null) ? $decoded['overrides'] : [];

        return [
            'platforms' => $platforms,
            'overrides' => $overrides,
        ];
    }

    /**
     * @param  array<string, mixed>  $postTypes
     */
    public static function isEmpty(array $postTypes): bool
    {
        $platforms = is_array($postTypes['platforms'] ?? null) ? $postTypes['platforms'] : [];

        foreach ($platforms as $byType) {
            if (! is_array($byType)) {
                continue;
            }

            foreach ($byType as $state) {
                if (is_string($state) && $state !== '') {
                    return false;
                }
            }
        }

        return true;
    }
}
