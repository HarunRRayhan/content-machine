<?php

namespace App\Support\Postsyncer;

use Carbon\CarbonImmutable;

/**
 * Verifies a PostSyncer post against the exact payload Content Machine sent.
 *
 * Reschedule uses PUT rather than delete-and-recreate, so it must prove that
 * the remote record still contains the reviewed content, media, accounts, and
 * platform settings before changing only its schedule.
 */
final class PostsyncerPostVerifier
{
    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $expectedPayload
     * @param  list<string>  $platforms
     * @return array<string, mixed>
     */
    public function assertMatches(
        array $response,
        array $expectedPayload,
        array $platforms,
        CarbonImmutable $expectedWhen,
        int|string $postsyncerPostId,
    ): array {
        $remote = $this->normalizeResponse($response);

        if ((string) ($remote['id'] ?? '') !== (string) $postsyncerPostId) {
            throw new PostsyncerException('The supplied PostSyncer post id was not found.');
        }

        $expectedWorkspaceId = $expectedPayload['workspace_id'] ?? null;
        $remoteWorkspaceId = $remote['workspace_id'] ?? data_get($remote, 'workspace.id');
        if ($expectedWorkspaceId === null
            || $remoteWorkspaceId === null
            || (string) $remoteWorkspaceId !== (string) $expectedWorkspaceId) {
            throw new PostsyncerException(
                'The supplied PostSyncer post belongs to a different workspace.',
            );
        }

        $this->assertContent($remote, $expectedPayload);
        $this->assertPlatforms($remote, $expectedPayload, $platforms);

        $status = strtoupper((string) ($remote['status'] ?? ''));
        if (! in_array($status, ['SCHEDULED', 'IN_QUEUE', 'PENDING', 'QUEUED'], true)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post is not reschedulable in its current state.',
            );
        }

        $scheduledAt = $remote['scheduled_at'] ?? null;
        if (! is_string($scheduledAt) || trim($scheduledAt) === '') {
            throw new PostsyncerException('The supplied PostSyncer post has no verifiable schedule.');
        }

        try {
            $remoteWhen = CarbonImmutable::parse($scheduledAt, $expectedWhen->timezone);
        } catch (\Throwable) {
            throw new PostsyncerException('The supplied PostSyncer post has an invalid schedule.');
        }

        if ($remoteWhen->format('Y-m-d H:i') !== $expectedWhen->format('Y-m-d H:i')) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the requested schedule.',
            );
        }

        return $remote;
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $expectedPayload
     */
    private function assertContent(array $remote, array $expectedPayload): void
    {
        $expectedContent = $expectedPayload['content'] ?? null;
        $remoteContent = $remote['content'] ?? null;

        if (! is_array($expectedContent)
            || ! is_array($remoteContent)
            || count($remoteContent) !== count($expectedContent)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the reviewed content.',
            );
        }

        foreach ($expectedContent as $index => $expectedItem) {
            $remoteItem = $remoteContent[$index] ?? null;
            if (! is_array($expectedItem) || ! is_array($remoteItem)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the reviewed content.',
                );
            }

            $expectedMedia = is_array($expectedItem['media'] ?? null)
                ? $expectedItem['media']
                : [];
            $remoteMedia = $remoteItem['media'] ?? [];

            if (($remoteItem['text'] ?? null) !== ($expectedItem['text'] ?? null)
                || ! is_array($remoteMedia)
                || count($remoteMedia) !== count($expectedMedia)
                || $this->responseMediaIds($remoteMedia) !== array_map('strval', $expectedMedia)
                || (bool) ($remoteItem['is_first_comment'] ?? false)
                    !== (bool) ($expectedItem['is_first_comment'] ?? false)
                || (int) ($remoteItem['first_comment_delay'] ?? 0)
                    !== (int) ($expectedItem['first_comment_delay'] ?? 0)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the reviewed content.',
                );
            }
        }
    }

    /**
     * @param  array<string, mixed>  $remote
     * @param  array<string, mixed>  $expectedPayload
     * @param  list<string>  $platforms
     */
    private function assertPlatforms(array $remote, array $expectedPayload, array $platforms): void
    {
        $remotePlatforms = $remote['platforms'] ?? null;
        $expectedAccounts = $expectedPayload['accounts'] ?? null;

        if (! is_array($remotePlatforms)
            || ! is_array($expectedAccounts)
            || count($remotePlatforms) !== count($platforms)
            || count($expectedAccounts) !== count($platforms)) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the reviewed platforms.',
            );
        }

        $actualPlatforms = [];
        foreach ($remotePlatforms as $platform) {
            if (is_array($platform) && is_string($platform['platform'] ?? null)) {
                $actualPlatforms[] = strtolower($platform['platform']);
            }
        }

        $expectedPlatforms = array_map('strtolower', $platforms);
        sort($actualPlatforms);
        sort($expectedPlatforms);

        if ($actualPlatforms !== $expectedPlatforms) {
            throw new PostsyncerException(
                'The supplied PostSyncer post does not match the reviewed platforms.',
            );
        }

        foreach ($platforms as $index => $platform) {
            $remotePlatform = null;
            foreach ($remotePlatforms as $candidate) {
                if (is_array($candidate)
                    && strtolower((string) ($candidate['platform'] ?? '')) === strtolower($platform)) {
                    $remotePlatform = $candidate;
                    break;
                }
            }

            $expectedAccount = $expectedAccounts[$index] ?? null;
            if (! is_array($remotePlatform) || ! is_array($expectedAccount)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post does not match the reviewed platforms.',
                );
            }

            $expectedAccountId = $expectedAccount['id'] ?? null;
            $remoteAccountId = $remotePlatform['account_id'] ?? data_get($remotePlatform, 'account.id');
            if ($expectedAccountId === null
                || $remoteAccountId === null
                || (string) $remoteAccountId !== (string) $expectedAccountId) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post targets a different account.',
                );
            }

            $expectedSettings = is_array($expectedAccount['settings'] ?? null)
                ? $expectedAccount['settings']
                : [];
            $remoteSettings = $remotePlatform['settings'] ?? null;

            if ($expectedSettings !== [] && ! is_array($remoteSettings)) {
                throw new PostsyncerException(
                    'The supplied PostSyncer post has no platform settings to verify.',
                );
            }

            // PostSyncer normalizes settings on GET. For example, it can move
            // a caption into the content item and omit that key from the
            // platform row. Compare keys that its canonical response exposes;
            // do not reject a reviewed payload solely because a provider
            // omitted an equivalent setting.
            if (is_array($remoteSettings)) {
                foreach ($expectedSettings as $setting => $value) {
                    if (array_key_exists($setting, $remoteSettings)
                        && $remoteSettings[$setting] !== $value) {
                        throw new PostsyncerException(
                            'The supplied PostSyncer post does not match the reviewed settings.',
                        );
                    }
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function normalizeResponse(array $response): array
    {
        return ! array_key_exists('id', $response) && is_array($response['data'] ?? null)
            ? $response['data']
            : $response;
    }

    /**
     * @param  array<int, mixed>  $media
     * @return list<string>
     */
    private function responseMediaIds(array $media): array
    {
        $ids = [];

        foreach ($media as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : $item;
            if (is_int($id) || (is_string($id) && ctype_digit($id))) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }
}
