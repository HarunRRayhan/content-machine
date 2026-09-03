<?php

namespace App\Support\Mcp;

use App\Models\Post;
use App\Models\Video;

/**
 * The tools this workspace exposes over MCP: scratchpad capture/triage,
 * idea read/edit, media browsing, video/post list/get/update, Drive browsing,
 * and publishing.
 *
 * @phpstan-type McpTool array{name: string, description: string, inputSchema: array<string, mixed>, ability: string}
 */
final class McpToolCatalog
{
    /**
     * @return list<McpTool>
     */
    public static function tools(): array
    {
        return [
            [
                'name' => 'list_scratchpad',
                'description' => 'List Content Machine Scratch Pad entries, newest first. Defaults to status=new (the untriaged inbox); use status=all/triaged/dropped.',
                'ability' => 'scratchpad:read',
                'inputSchema' => self::schema([
                    'status' => ['type' => 'string', 'enum' => ['new', 'triaged', 'dropped', 'all']],
                    'kind' => ['type' => 'string', 'enum' => ['text', 'link', 'photo', 'voice', 'file']],
                ]),
            ],
            [
                'name' => 'get_scratchpad',
                'description' => 'Fetch one Scratch Pad entry in full by its public_id.',
                'ability' => 'scratchpad:read',
                'inputSchema' => self::schema([
                    'public_id' => ['type' => 'string', 'description' => 'The entry ULID from list_scratchpad.'],
                ], ['public_id']),
            ],
            [
                'name' => 'capture_note',
                'description' => 'Capture a plain text note into the Scratch Pad.',
                'ability' => 'scratchpad:write',
                'inputSchema' => self::schema([
                    'body' => ['type' => 'string', 'minLength' => 1],
                ], ['body']),
            ],
            [
                'name' => 'capture_link',
                'description' => 'Capture a URL into the Scratch Pad. Content Machine resolves it in the background.',
                'ability' => 'scratchpad:write',
                'inputSchema' => self::schema([
                    'url' => ['type' => 'string', 'format' => 'uri'],
                ], ['url']),
            ],
            [
                'name' => 'list_media',
                'description' => 'List workspace media (images, videos, GIFs) for reuse. Filter by tab (images/videos/gifs) and search title, description, or filename with q.',
                'ability' => 'media:read',
                'inputSchema' => self::schema([
                    'tab' => ['type' => 'string', 'enum' => ['images', 'videos', 'gifs']],
                    'q' => ['type' => 'string', 'description' => 'Search title, description, or filename.'],
                ]),
            ],
            [
                'name' => 'update_scratchpad',
                'description' => 'Edit an entry title, body, or language. Dropped entries refuse edits.',
                'ability' => 'scratchpad:write',
                'inputSchema' => self::schema([
                    'public_id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'language' => ['type' => 'string', 'description' => 'e.g. bn or en'],
                ], ['public_id']),
            ],
            [
                'name' => 'delete_scratchpad',
                'description' => 'Hard-delete an untriaged entry. Refused if it is already an idea.',
                'ability' => 'scratchpad:write',
                'inputSchema' => self::schema([
                    'public_id' => ['type' => 'string'],
                ], ['public_id']),
            ],
            [
                'name' => 'triage_scratchpad',
                'description' => 'Route an entry: target=post_idea or video_idea files it as PI-N/VI-N (title required); target=drop needs drop_reason.',
                'ability' => 'scratchpad:write',
                'inputSchema' => self::schema([
                    'public_id' => ['type' => 'string'],
                    'target' => ['type' => 'string', 'enum' => ['post_idea', 'video_idea', 'drop']],
                    'title' => ['type' => 'string'],
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
                    'trend' => ['type' => 'string', 'enum' => ['evergreen', 'seasonal']],
                    'rationale' => ['type' => 'string'],
                    'drop_reason' => ['type' => 'string'],
                ], ['public_id', 'target']),
            ],
            [
                'name' => 'list_ideas',
                'description' => 'List ideas (PI-/VI-), optionally filtered by kind and status.',
                'ability' => 'ideas:read',
                'inputSchema' => self::schema([
                    'kind' => ['type' => 'string', 'enum' => ['post', 'video', 'feature']],
                    'status' => ['type' => 'string', 'enum' => ['open', 'promoted', 'dropped']],
                ]),
            ],
            [
                'name' => 'get_idea',
                'description' => 'Fetch one idea by human_id (e.g. PI-7).',
                'ability' => 'ideas:read',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string'],
                ], ['human_id']),
            ],
            [
                'name' => 'update_idea',
                'description' => 'Edit an idea title (required) and optionally score, trend, rationale, or body.',
                'ability' => 'ideas:write',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'score' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 1000],
                    'trend' => ['type' => 'string', 'enum' => ['evergreen', 'seasonal']],
                    'rationale' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                ], ['human_id', 'title']),
            ],
            [
                'name' => 'list_videos',
                'description' => 'List videos, newest first (by number). Optionally filter by status or language. Limit 50.',
                'ability' => 'videos:read',
                'inputSchema' => self::schema([
                    'status' => ['type' => 'string', 'enum' => Video::STATUSES],
                    'language' => ['type' => 'string', 'description' => 'e.g. bn or en'],
                ]),
            ],
            [
                'name' => 'get_video',
                'description' => 'Fetch one video by human_id (e.g. BV-50 or V-12).',
                'ability' => 'videos:read',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string', 'description' => 'The video id, e.g. BV-50 or V-12.'],
                ], ['human_id']),
            ],
            [
                'name' => 'update_video',
                'description' => 'Update an existing video. human_id required; optional title, language, slug, body, script_markdown, deck_manifest, status, video_drive_url, cover_drive_url. Drive URLs must be publicly fetchable (Anyone with the link).',
                'ability' => 'videos:write',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'language' => ['type' => 'string'],
                    'slug' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'script_markdown' => ['type' => 'string'],
                    'deck_manifest' => ['type' => ['object', 'null'], 'description' => 'Registered, renderable presentation deck package; pass null to remove it.'],
                    'status' => ['type' => 'string', 'enum' => Video::STATUSES],
                    'video_drive_url' => ['type' => 'string', 'description' => 'Public Google Drive file link for the edited video.'],
                    'cover_drive_url' => ['type' => 'string', 'description' => 'Public Google Drive file link for the cover image.'],
                ], ['human_id']),
            ],
            [
                'name' => 'check_drive_url',
                'description' => 'Check whether a pasted Google Drive file link is publicly fetchable. Returns accessible, message, file_id, share_url, fetch_url. Use before update_video / update_post.',
                'ability' => 'videos:write',
                'inputSchema' => self::schema([
                    'url' => ['type' => 'string', 'description' => 'A Google Drive file share link.'],
                ], ['url']),
            ],
            [
                'name' => 'list_drive_files',
                'description' => 'List files in the connected workspace Google Drive folder. Use folder_id to browse into a returned folder and q to search.',
                'ability' => 'drive:read',
                'inputSchema' => self::schema([
                    'folder_id' => ['type' => 'string'],
                    'q' => ['type' => 'string', 'description' => 'Search names in the selected folder.'],
                    'page_token' => ['type' => 'string'],
                ]),
            ],
            [
                'name' => 'make_drive_file_public',
                'description' => 'Grant a connected Google Drive file an Anyone-with-the-link reader permission and return its share URL.',
                'ability' => 'drive:write',
                'inputSchema' => self::schema([
                    'file_id' => ['type' => 'string', 'description' => 'The Drive file id returned by list_drive_files.'],
                ], ['file_id']),
            ],
            [
                'name' => 'publish_video',
                'description' => 'Queue a video for PostSyncer. The video needs a Video Drive URL first. Always pass when (ISO datetime) to schedule. Omitting when publishes immediately. After a schedule succeeds, verify the record and finish the Drive/tracker handoff.',
                'ability' => 'videos:write',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string', 'description' => 'The video id, e.g. BV-50 or V-12.'],
                    'when' => ['type' => 'string', 'description' => 'ISO datetime to schedule. Omit only for an immediate publish.'],
                    'platforms' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'confirm_ask' => ['type' => 'boolean'],
                ], ['human_id']),
            ],
            [
                'name' => 'list_posts',
                'description' => 'List posts, newest first (by number). Optionally filter by status or language. Limit 50.',
                'ability' => 'posts:read',
                'inputSchema' => self::schema([
                    'status' => ['type' => 'string', 'enum' => Post::STATUSES],
                    'language' => ['type' => 'string', 'description' => 'e.g. bn or en'],
                ]),
            ],
            [
                'name' => 'get_post',
                'description' => 'Fetch one post by human_id (e.g. P-50 or BP-7).',
                'ability' => 'posts:read',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string', 'description' => 'The post id, e.g. P-50 or BP-7.'],
                ], ['human_id']),
            ],
            [
                'name' => 'update_post',
                'description' => 'Update an existing post. human_id required; optional title, body, captions, platforms, status, image_drive_urls. Drive URLs must be publicly fetchable.',
                'ability' => 'posts:write',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'body' => ['type' => 'string'],
                    'captions' => ['type' => 'object', 'description' => 'Per-platform caption map.'],
                    'platforms' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'status' => ['type' => 'string', 'enum' => Post::STATUSES],
                    'image_drive_urls' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Public Google Drive file links for post images.'],
                ], ['human_id']),
            ],
            [
                'name' => 'publish_post',
                'description' => 'Queue a post for PostSyncer. Always pass when (ISO datetime) to schedule. Omitting when publishes immediately. Optional platforms list and confirm_ask for ask-gated photo platforms.',
                'ability' => 'posts:write',
                'inputSchema' => self::schema([
                    'human_id' => ['type' => 'string', 'description' => 'The post id, e.g. P-50 or BP-7.'],
                    'when' => ['type' => 'string', 'description' => 'ISO datetime to schedule. Omit only for an immediate publish.'],
                    'platforms' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'confirm_ask' => ['type' => 'boolean', 'description' => 'Required true when English Twitter/Threads/Bluesky photo posts are in the set.'],
                ], ['human_id']),
            ],
        ];
    }

    /**
     * Public tool list for tools/list: ability is internal, not part of MCP.
     *
     * @return list<array{name: string, description: string, inputSchema: array<string, mixed>}>
     */
    public static function published(): array
    {
        return array_map(
            fn (array $tool): array => [
                'name' => $tool['name'],
                'description' => $tool['description'],
                'inputSchema' => $tool['inputSchema'],
            ],
            self::tools(),
        );
    }

    /**
     * @return McpTool|null
     */
    public static function find(string $name): ?array
    {
        foreach (self::tools() as $tool) {
            if ($tool['name'] === $name) {
                return $tool;
            }
        }

        return null;
    }

    /**
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    private static function schema(array $properties, array $required = []): array
    {
        $schema = [
            'type' => 'object',
            'properties' => $properties,
        ];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }
}
