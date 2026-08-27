<?php

namespace App\Support\Postsyncer;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class PostsyncerClient
{
    public function __construct(
        private readonly PostsyncerConfig $config,
    ) {}

    /**
     * PostSyncer workspaces from GET /workspaces, including the display name.
     *
     * @return list<array{id: string, name: string}>
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
            $workspaces[] = [
                'id' => $id,
                'name' => $this->workspaceName($row, $id),
            ];
        }

        return $workspaces;
    }

    /**
     * @return array<int, array<string, mixed>>
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
     * @return list<int|string>
     */
    public function uploadFromUrls(int|string $workspaceId, array $urls): array
    {
        $response = $this->request('post', '/media/upload/url', [
            'workspace_id' => $workspaceId,
            'urls' => $urls,
        ]);
        $data = $this->decodeResponse($response);

        $media = $data['media'] ?? [];

        if (! is_array($media)) {
            return [];
        }

        $ids = [];

        foreach ($media as $item) {
            if (is_array($item) && array_key_exists('id', $item)) {
                $ids[] = $item['id'];
            }
        }

        return $ids;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function createPost(array $body): array
    {
        $response = $this->request('post', '/posts', $body);

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    public function getPost(int|string $id): array
    {
        return $this->decodeResponse($this->request('get', '/posts/'.$id));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function request(string $method, string $path, array $body = []): Response
    {
        $apiKey = $this->config->apiKey();

        if (! is_string($apiKey) || $apiKey === '') {
            throw new PostsyncerException('PostSyncer API key is not configured.');
        }

        $url = rtrim($this->config->apiBase(), '/').$path;

        $pending = Http::withToken($apiKey)->timeout(30)->acceptJson();

        $response = match ($method) {
            'get' => $pending->get($url),
            'post' => $pending->post($url, $body),
            default => throw new \InvalidArgumentException("Unsupported HTTP method: {$method}"),
        };

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
}
