<?php

namespace App\Support\Socialreins;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * SocialReins (brain) API v1 for one language: its set-limited token.
 * Every write carries an Idempotency-Key.
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
     * POST /post-groups with the exact JSON body that was frozen for this key.
     *
     * @return array{group_id: string, posts: array<mixed>}
     */
    public function createGroup(string $key, string $json): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->withHeaders(['Idempotency-Key' => $key])
            ->withBody($json, 'application/json')
            ->post($this->url('/post-groups')));

        $body = $this->decode($response);
        if (! is_string($body['group_id'] ?? null) || ! is_array($body['posts'] ?? null)) {
            throw new SocialreinsException('SocialReins answered without a group id.', $response->status());
        }

        return $body;
    }

    /**
     * GET /posts?group_id= : the group's posts with their targets.
     *
     * @return list<array<string, mixed>>
     */
    public function postsOfGroup(string $groupId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http
            ->get($this->url('/posts'), ['group_id' => $groupId]));

        return array_values(array_filter($this->decode($response), 'is_array'));
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

        throw new SocialreinsException(
            'SocialReins answered '.$response->status()
                .(is_string($code) ? " {$code}" : '')
                .(is_string($platform) ? " (platform {$platform})" : '')
                .(is_string($detail) ? ": {$detail}" : '').'.',
            $response->status(),
            is_string($code) ? $code : null,
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
