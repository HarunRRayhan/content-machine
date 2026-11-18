<?php

namespace App\Support\Publishing;

/**
 * Copied from personal-content/web/workspaces.json, the same map Script Studio bakes into
 * WORKSPACES: the account handles the post preview shows.
 */
class StudioWorkspaceHandles
{
    /**
     * @return array{bn: array<string, string>, en: array<string, string>}
     */
    public static function handles(): array
    {
        return [
            'bn' => [
                'facebook' => 'HarunRRayhan',
                'instagram' => 'harunrrayhan',
                'tiktok' => 'harunrrayhan',
                'youtube' => 'skillupwithharun',
                'twitter' => 'HarunRRayhan',
                'threads' => 'harunrrayhan',
                'bluesky' => 'harunrrayhan.bsky.social',
                'linkedin' => '',
            ],
            'en' => [
                'facebook' => 'harundotdev',
                'instagram' => 'harundotdev',
                'tiktok' => 'harundotdev',
                'youtube' => 'harundotdev',
                'twitter' => 'harundotdev',
                'threads' => 'harundotdev',
                'bluesky' => 'harun.dev',
                'linkedin' => 'harundotdev',
            ],
        ];
    }
}
