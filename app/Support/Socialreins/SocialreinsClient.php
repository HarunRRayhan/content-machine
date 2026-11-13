<?php

namespace App\Support\Socialreins;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SocialReins (brain) API v1 for one language: its set-limited token.
 * Every write carries an Idempotency-Key, except updatePost() (see there).
 */
class SocialreinsClient
{
    /** Set by send() before each call builds its URL. */
    private string $resolved = '';

    public function __construct(
        private readonly SocialreinsConfig $config,
        private readonly string $language,
    ) {}

    /**
     * POST /post-groups with the exact JSON body that was frozen for this key. `skipped`
     * lists the platforms brain left out as invalid (`on_invalid: skip`).
     *
     * @return array{group_id: string, posts: array<mixed>, expected_targets: int, skipped: array<mixed>}
     */
    public function createGroup(string $key, string $json): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $key])
            ->withBody($json, 'application/json')
            ->post($this->url('/post-groups')));

        return [...$this->groupBody($response), 'skipped' => (array) $response->json('skipped', [])];
    }

    /**
     * GET /post-groups/{id}: the group's posts with their targets and `expected_targets`.
     *
     * @return array{group_id: string, posts: array<mixed>, expected_targets: int}
     */
    public function group(string $groupId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->get($this->url('/post-groups/'.rawurlencode($groupId))));

        return $this->groupBody($response);
    }

    /**
     * POST /post-groups/{id}/retry: re-queues the group's failed targets that are not
     * ambiguous. An empty body, so every attempt needs its own key.
     *
     * @return array{group_id: string, posts: array<mixed>, expected_targets: int, skipped_targets: array<mixed>}
     */
    public function retryGroup(string $groupId, string $key): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $key])
            ->post($this->url('/post-groups/'.rawurlencode($groupId).'/retry')));

        return [...$this->groupBody($response), 'skipped_targets' => (array) $response->json('skipped_targets', [])];
    }

    /**
     * GET /idempotency/{key}: what a key made, for a request whose answer was lost.
     *
     * @return array<string, mixed>|null null when brain has nothing stored under it (the first
     *                                   request answered 5xx or 429, or never arrived)
     */
    public function lookupKey(string $key): ?array
    {
        try {
            return $this->decode($this->send(fn (PendingRequest $http) => $http
                ->get($this->url('/idempotency/'.rawurlencode($key)))));
        } catch (SocialreinsException $exception) {
            if ($exception->status === 404) {
                return null;
            }

            throw $exception;
        }
    }

    /** @return array{group_id: string, posts: array<mixed>, expected_targets: int} */
    private function groupBody(Response $response): array
    {
        $body = $this->decode($response);
        if (! is_string($body['group_id'] ?? null) || ! is_array($body['posts'] ?? null)) {
            throw new SocialreinsException('SocialReins answered without a group.', $response->status());
        }

        return [
            'group_id' => $body['group_id'],
            'posts' => $body['posts'],
            'expected_targets' => (int) ($body['expected_targets'] ?? 0),
        ];
    }

    /**
     * DELETE /posts/{id}: takes a published post down from every account.
     *
     * @return array<string, mixed>
     */
    public function deletePost(string $postId, string $key): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $key])
            ->delete($this->url('/posts/'.rawurlencode($postId))));

        return $response->status() === 204 ? [] : $this->decode($response);
    }

    /**
     * GET /posts/{id}: one brain post with its status and scheduled_at.
     *
     * @return array<string, mixed>
     */
    public function post(string $postId): array
    {
        return $this->decode($this->send(fn (PendingRequest $http) => $http
            ->get($this->url('/posts/'.rawurlencode($postId)))));
    }

    /**
     * PUT /posts/{id}: replaces a draft or scheduled post (409 not_editable otherwise). No
     * Idempotency-Key: the same body twice leaves the same post, and a caller re-reads first.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updatePost(string $postId, array $body): array
    {
        return $this->decode($this->send(fn (PendingRequest $http) => $http
            ->put($this->url('/posts/'.rawurlencode($postId)), $body)));
    }

    /** @param  callable(PendingRequest): Response  $call */
    private function send(callable $call): Response
    {
        $token = $this->config->token($this->language);
        if ($token === null) {
            throw new SocialreinsException("No SocialReins token is set for {$this->language}.");
        }

        try {
            // The token only ever goes to a public HTTPS host, on the addresses just
            // checked, and never follows a redirect (which would carry it elsewhere).
            $base = (new SocialreinsBaseUrl)->resolve($this->config->baseUrl());
            $this->resolved = $base['url'];
            $response = $call(Http::withToken($token)->acceptJson()->timeout(30)
                ->withoutRedirecting()->withOptions($base['options']));
        } catch (\InvalidArgumentException $exception) {
            throw new SocialreinsException($exception->getMessage());
        } catch (ConnectionException $exception) {
            throw new SocialreinsException('SocialReins could not be reached: '.$exception->getMessage());
        }

        if ($response->successful()) {
            return $response;
        }

        $code = $response->json('error');
        $detail = $response->json('message');
        $platform = $response->json('platform');

        if ($code === 'tiktok_settings_required') {
            throw new SocialreinsException(
                'SocialReins refused the TikTok post: no TikTok visibility was sent. Choose one in Settings > SocialReins '
                .'(TikTok has no default and is never made public for you).',
                $response->status(),
                $code,
            );
        }

        throw new SocialreinsException(
            'SocialReins answered '.$response->status()
                .(is_string($code) ? " {$code}" : '')
                .(is_string($platform) ? " (platform {$platform})" : '')
                .(is_string($detail) ? ": {$detail}" : '')
                // Brain's 422s say what exactly is wrong (which part, the limit, the actual size).
                .(is_string($why = $response->json('detail')) && $why !== '' && $why !== $detail ? " ({$why})" : '').'.',
            $response->status(),
            is_string($code) ? $code : null,
            (array) $response->json(),
        );
    }

    /** @return array<mixed> */
    private function decode(Response $response): array
    {
        $body = $response->json();

        if (! is_array($body)) {
            throw new SocialreinsException('SocialReins answered with a body that is not JSON.', $response->status());
        }

        return $body;
    }

    private function url(string $path): string
    {
        return $this->resolved.$path;
    }
}
