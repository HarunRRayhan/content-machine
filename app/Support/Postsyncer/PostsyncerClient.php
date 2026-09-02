<?php

namespace App\Support\Postsyncer;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

class PostsyncerClient
{
    public function __construct(
        private readonly PostsyncerConfig $config,
    ) {}

    /**
     * PostSyncer workspaces from GET /workspaces, including the display name.
     *
     * @return list<array{id: string, name: string, accounts: list<array{id: string, platform: string, handle: string}>}>
     */
    public function listWorkspaces(): array
    {
        $response = $this->request('get', '/workspaces');
        $data = $this->decodeResponse($response);
        $rows = $data['data'] ?? $data;

        if (! is_array($rows)) {
            return [];
        }

        $workspaces = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $id = (string) $id;
            $accounts = is_array($row['accounts'] ?? null) ? $row['accounts'] : [];
            $workspaces[] = [
                'id' => $id,
                'name' => $this->workspaceName($row, $id),
                'accounts' => MapPostsyncerAccounts::present($accounts, $id),
            ];
        }

        return $workspaces;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAccounts(int|string $workspaceId): array
    {
        return array_values(array_filter(
            $this->allAccounts(),
            fn (array $account): bool => (string) ($account['workspace_id'] ?? '') === (string) $workspaceId,
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listAllAccounts(): array
    {
        return $this->allAccounts();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function allAccounts(): array
    {
        $response = $this->request('get', '/accounts');
        $data = $this->decodeResponse($response);

        $accounts = $data['data'] ?? $data;

        if (! is_array($accounts)) {
            return [];
        }

        return array_values(array_filter($accounts, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $workspace
     */
    private function workspaceName(array $workspace, string $fallbackId): string
    {
        foreach (['name', 'slug'] as $key) {
            $value = $workspace[$key] ?? null;

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return $fallbackId;
    }

    /**
     * @param  list<string>  $urls
     * @param  string|null  $idempotencyKey  Stable per-operation/group key for providers that support replay deduplication.
     * @return list<int|string>
     */
    public function uploadFromUrls(int|string $workspaceId, array $urls, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey === null || trim($idempotencyKey) === ''
            ? []
            : ['Idempotency-Key' => $idempotencyKey];
        $response = $this->request('post', '/media/upload/url', [
            'workspace_id' => $workspaceId,
            'urls' => $urls,
        ], $headers);
        $data = $this->decodeResponse($response);

        $media = $data['media'] ?? [];

        if (! is_array($media)) {
            throw new PostsyncerException(
                'PostSyncer returned an invalid media upload response. Refusing to publish with invalid media.'
            );
        }

        $countStored = $data['count_stored'] ?? null;
        if ($countStored !== null
            && (! is_int($countStored) || $countStored !== count($media))
        ) {
            throw new PostsyncerException(
                'PostSyncer returned an incomplete media upload response. Refusing to publish with missing media.'
            );
        }

        $ids = [];

        foreach ($media as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! $this->hasNumericId($id)) {
                throw new PostsyncerException(
                    'PostSyncer returned a media item without a valid id. Refusing to publish with invalid media.'
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  string|null  $idempotencyKey  Stable per-operation/group key for providers that support replay deduplication.
     * @return array<string, mixed>
     */
    public function createPost(array $body, ?string $idempotencyKey = null): array
    {
        $headers = $idempotencyKey === null || trim($idempotencyKey) === ''
            ? []
            : ['Idempotency-Key' => $idempotencyKey];
        $response = $this->request('post', '/posts', $body, $headers);

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPost(int|string $id): array
    {
        return $this->decodeResponse($this->request('get', '/posts/'.rawurlencode((string) $id)));
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $path, array $body = [], array $headers = []): Response
    {
        $apiKey = $this->config->apiKey();

        if (! is_string($apiKey) || $apiKey === '') {
            throw new PostsyncerException('PostSyncer API key is not configured.');
        }

        $url = rtrim($this->config->apiBase(), '/').$path;

        $pending = Http::withToken($apiKey)
            ->timeout(30)
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->withHeaders($headers);

        try {
            $response = match ($method) {
                'get' => $pending->get($url),
                'post' => $pending->post($url, $body),
                default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
            };
        } catch (Throwable $exception) {
            // A connection timeout may happen after PostSyncer accepted a
            // create. Keep it in the unknown-outcome path so retries cannot
            // silently issue a second create without reconciliation.
            throw new PostsyncerException(
                'Could not reach PostSyncer: '.$exception->getMessage(),
                0,
                $exception,
                true,
                true,
                false,
            );
        }

        if (! $response->successful()) {
            throw PostsyncerException::fromResponse($response);
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(Response $response): array
    {
        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    private function hasNumericId(mixed $id): bool
    {
        return is_int($id)
            ? $id > 0
            : is_string($id) && ctype_digit($id) && (int) $id > 0;
    }
}
