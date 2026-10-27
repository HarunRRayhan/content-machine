<?php

namespace App\Support\Postsyncer;

use App\Support\LinkResolution\PublicUrlGuard;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Psr7\UriResolver;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Symfony\Component\Mime\MimeTypes;
use Throwable;

class PostsyncerClient
{
    /** PostSyncer's documented limit for POST /media/upload/file. */
    public const MAX_FILE_UPLOAD_BYTES = 500 * 1024 * 1024;

    private const MAX_DOWNLOAD_REDIRECTS = 5;

    private const UPLOAD_RATE_LIMIT_BACKOFF_SECONDS = [30, 60, 60];

    private const UPLOAD_RATE_LIMIT_MAX_WAIT_SECONDS = 60;

    // Shared by every file in one publish group so retries stay well inside the job timeout.
    private const UPLOAD_RATE_LIMIT_TOTAL_WAIT_SECONDS = 120;

    public function __construct(
        private readonly PostsyncerConfig $config,
        private readonly ?PublicUrlGuard $urlGuard = null,
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

        $media = $data['media'] ?? null;
        $countStored = $data['count_stored'] ?? null;

        if (! is_array($media)
            || ! is_int($countStored)
            || $countStored !== count($media)) {
            throw new PostsyncerException(
                'PostSyncer returned an incomplete media upload response. '
                .'Refusing to publish with missing media.'
            );
        }

        if ($media === [] && $urls === []) {
            return [];
        }

        // A successful response that stored nothing is a definitive upload
        // rejection. Unlike a timeout or a partial response, it is safe to
        // retry because PostSyncer returned no media ids at all.
        if ($countStored === 0) {
            throw new PostsyncerException(
                'PostSyncer returned an incomplete media upload response. '
                .'Refusing to publish with missing media.',
                safeToRetry: true,
                responseReceived: true,
            );
        }

        if ($countStored < count($urls)) {
            throw new PostsyncerException(
                'PostSyncer returned an incomplete media upload response. '
                .'Refusing to publish with missing media.'
            );
        }

        $ids = [];

        foreach ($media as $item) {
            $id = is_array($item) ? ($item['id'] ?? null) : null;

            if (! $this->hasMediaId($id)) {
                throw new PostsyncerException(
                    'PostSyncer returned a media item without a valid id. '
                    .'Refusing to publish with invalid media.'
                );
            }

            $ids[] = $id;
        }

        return $ids;
    }

    /**
     * Upload media through PostSyncer's multipart file endpoint, one request
     * per file, because its URL import stores no videos (2026-10-26). Every
     * URL is downloaded first, so a download failure happens before anything
     * reaches PostSyncer.
     *
     * @param  list<string>  $urls
     * @param  string|null  $idempotencyKey  Stable per-operation/group key; each file gets its own suffix.
     * @return list<int|string> Media ids in the same order as $urls.
     */
    public function uploadFilesFromUrls(int|string $workspaceId, array $urls, ?string $idempotencyKey = null): array
    {
        $paths = [];
        $waitBudget = self::UPLOAD_RATE_LIMIT_TOTAL_WAIT_SECONDS;

        try {
            foreach ($urls as $url) {
                $path = tempnam(sys_get_temp_dir(), 'cm-media-');
                if ($path === false) {
                    throw $this->safeUploadFailure('Could not create a temporary file for the media download.');
                }
                $paths[] = $path;
                $this->downloadMedia($url, $path);
            }

            $ids = [];

            foreach ($paths as $index => $path) {
                $headers = $idempotencyKey === null || trim($idempotencyKey) === ''
                    ? []
                    : ['Idempotency-Key' => $idempotencyKey.':'.$index];

                try {
                    $response = $this->uploadFileWithRateLimitRetry($workspaceId, $headers, $path, $waitBudget);
                } catch (PostsyncerException $exception) {
                    // A definitive rejection before any file was stored left
                    // nothing behind in PostSyncer, so a retry cannot duplicate.
                    if ($ids === [] && $exception->responseReceived && ! $exception->outcomeUnknown) {
                        throw new PostsyncerException(
                            $exception->getMessage(),
                            $exception->getCode(),
                            $exception,
                            responseReceived: true,
                            safeToRetry: true,
                        );
                    }

                    if ($ids !== []) {
                        // Name the orphaned media so reconciliation can find it.
                        throw new PostsyncerException(
                            $exception->getMessage().' Already stored PostSyncer media ids: '.implode(', ', $ids).'.',
                            $exception->getCode(),
                            $exception,
                            $exception->retryable,
                            $exception->outcomeUnknown,
                            $exception->responseReceived,
                            $exception->safeToRetry,
                        );
                    }

                    throw $exception;
                }

                $id = $this->decodeResponse($response)['media']['id'] ?? null;

                if (! $this->hasMediaId($id)) {
                    throw new PostsyncerException(
                        'PostSyncer returned a media item without a valid id. '
                        .'Refusing to publish with invalid media.'
                    );
                }

                $ids[] = $id;
            }

            return $ids;
        } finally {
            foreach ($paths as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * PostSyncer's upload throttle answers 429 before storing anything, so the
     * same file is retried after Retry-After (capped) or a fixed backoff. All
     * files in one call share a 120 s wait budget, so retries plus re-sent
     * uploads stay inside the 900-second publish job timeout.
     * A 429 that outlasts the retries is still a definitive rejection.
     *
     * @param  array<string, string>  $headers
     */
    private function uploadFileWithRateLimitRetry(int|string $workspaceId, array $headers, string $path, int &$waitBudget): Response
    {
        $backoff = self::UPLOAD_RATE_LIMIT_BACKOFF_SECONDS;

        for ($attempt = 0; ; $attempt++) {
            try {
                return $this->request('post', '/media/upload/file', [
                    'workspace_id' => $workspaceId,
                ], $headers, $path);
            } catch (PostsyncerException $exception) {
                if ($exception->getCode() !== 429) {
                    throw $exception;
                }

                $wait = min($exception->retryAfter ?? $backoff[$attempt] ?? 0, self::UPLOAD_RATE_LIMIT_MAX_WAIT_SECONDS);

                if ($attempt >= count($backoff) || $wait > $waitBudget) {
                    throw new PostsyncerException(
                        $exception->getMessage().' Gave up after '.count($backoff).' retries.',
                        429,
                        $exception,
                        responseReceived: true,
                    );
                }

                $waitBudget -= $wait;
                Sleep::for($wait)->seconds();
            }
        }
    }

    private function downloadMedia(string $url, string $path): void
    {
        $guard = $this->urlGuard ?? app(PublicUrlGuard::class);
        $overCap = false;
        $progress = $this->downloadCapProgress($overCap);

        for ($hop = 0; ; $hop++) {
            $curlOptions = $this->isDriveDownloadUrl($url) && $guard->isSafe($url)
                ? $guard->curlResolveOptions($url)
                : null;

            if ($curlOptions === null) {
                throw $this->safeUploadFailure('Refusing to download media from a URL that is not a public Google Drive download.');
            }

            try {
                $response = Http::timeout(300)
                    ->sink($path)
                    ->withOptions([
                        'allow_redirects' => false,
                        'progress' => $progress,
                        ...$curlOptions,
                    ])
                    ->get($url);
            } catch (Throwable $exception) {
                throw $overCap
                    ? $this->oversizeFailure()
                    : $this->safeUploadFailure('Could not download media before uploading it to PostSyncer: '
                        .$exception->getMessage(), $exception);
            }

            if (! $response->redirect()) {
                break;
            }

            $location = $response->header('Location');
            if ($location === '' || $hop >= self::MAX_DOWNLOAD_REDIRECTS) {
                throw $this->safeUploadFailure('Could not download media before uploading it to PostSyncer: too many redirects.');
            }

            $url = (string) UriResolver::resolve(new Uri($url), new Uri($location));
        }

        if (! $response->successful()) {
            throw $this->safeUploadFailure('Could not download media before uploading it to PostSyncer: HTTP '
                .$response->status().'.');
        }

        $length = $response->header('Content-Length');
        if ($length !== '' && (int) $length > static::MAX_FILE_UPLOAD_BYTES) {
            throw $this->oversizeFailure();
        }

        clearstatcache(true, $path);
        $size = (int) filesize($path);

        if ($size === 0) {
            throw $this->safeUploadFailure('The downloaded media file is empty. Refusing to upload it to PostSyncer.');
        }

        if ($size > static::MAX_FILE_UPLOAD_BYTES) {
            throw $this->oversizeFailure();
        }

        // Drive serves an HTML page instead of the file when sharing is off.
        if (str_starts_with((string) MimeTypes::getDefault()->guessMimeType($path), 'text/html')) {
            throw $this->safeUploadFailure('The media URL returned an HTML page instead of a file. '
                .'Check that the Drive file is shared publicly.');
        }
    }

    /**
     * Guzzle's progress option: a truthy return makes its cURL handler abort
     * the transfer (Guzzle 8 rejects raw CURLOPT_PROGRESSFUNCTION), as soon
     * as Content-Length or the received bytes pass the cap.
     */
    private function downloadCapProgress(bool &$overCap): \Closure
    {
        return function (int $expected, int $downloaded) use (&$overCap): bool {
            $overCap = $expected > static::MAX_FILE_UPLOAD_BYTES || $downloaded > static::MAX_FILE_UPLOAD_BYTES;

            return $overCap;
        };
    }

    private function isDriveDownloadUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        return strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && (in_array($host, ['drive.usercontent.google.com', 'drive.google.com'], true)
                || str_ends_with($host, '.googleusercontent.com'));
    }

    private function oversizeFailure(): PostsyncerException
    {
        return $this->safeUploadFailure(sprintf(
            'The media file is over PostSyncer\'s %d MB upload limit.',
            static::MAX_FILE_UPLOAD_BYTES / 1024 / 1024,
        ));
    }

    /**
     * Nothing was sent to PostSyncer, so retrying cannot duplicate media.
     */
    private function safeUploadFailure(string $message, ?Throwable $previous = null): PostsyncerException
    {
        return new PostsyncerException($message, 0, $previous, responseReceived: true, safeToRetry: true);
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
     * Update an existing, not-yet-published PostSyncer post.
     *
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function updatePost(int|string $id, array $body): array
    {
        return $this->decodeResponse(
            $this->request('put', '/posts/'.rawurlencode((string) $id), $body),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getPost(int|string $id): array
    {
        return $this->decodeResponse($this->request('get', '/posts/'.rawurlencode((string) $id)));
    }

    /**
     * PostSyncer's post resource omits account ids. Its analytics resource
     * includes one account row per platform, which is needed when verifying a
     * create response or reconciling an uncertain create.
     *
     * @return array<string, mixed>
     */
    public function getPostWithAccountDetails(int|string $id): array
    {
        $post = $this->unwrapData($this->getPost($id));
        $platforms = $post['platforms'] ?? null;

        if (! is_array($platforms) || ! $this->needsAccountDetails($platforms)) {
            return $post;
        }

        $analytics = $this->unwrapData($this->getPostAnalytics($id));
        $accountRows = $analytics['accounts'] ?? null;
        if (! is_array($accountRows)) {
            return $post;
        }

        $byPlatform = [];
        foreach ($accountRows as $row) {
            if (! is_array($row) || ! is_string($row['platform'] ?? null)) {
                continue;
            }

            $accountId = $row['account_id'] ?? data_get($row, 'account.id');
            if ($accountId === null || $accountId === '') {
                continue;
            }

            $byPlatform[strtolower($row['platform'])][] = $row;
        }

        foreach ($platforms as $index => $platform) {
            if (! is_array($platform)) {
                continue;
            }

            $existingAccountId = $platform['account_id'] ?? data_get($platform, 'account.id');
            if ($existingAccountId !== null && $existingAccountId !== '') {
                continue;
            }

            $name = strtolower((string) ($platform['platform'] ?? ''));
            $row = $byPlatform[$name][0] ?? null;
            if (! is_array($row)) {
                continue;
            }

            $platform['account_id'] = $row['account_id'] ?? data_get($row, 'account.id');
            if (is_array($row['account'] ?? null)) {
                $platform['account'] = $row['account'];
            }
            $platforms[$index] = $platform;
        }

        $post['platforms'] = $platforms;

        return $post;
    }

    /**
     * @return array<string, mixed>
     */
    public function getPostAnalytics(int|string $id): array
    {
        return $this->decodeResponse(
            $this->request('get', '/analytics/posts/'.rawurlencode((string) $id)),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    private function request(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        ?string $filePath = null,
    ): Response {
        $apiKey = $this->config->apiKey();

        if (! is_string($apiKey) || $apiKey === '') {
            throw new PostsyncerException('PostSyncer API key is not configured.');
        }

        // File uploads must go to the dedicated upload host, never the API host.
        $base = $filePath === null ? $this->config->apiBase() : $this->config->uploadBase();
        $url = rtrim($base, '/').$path;

        $pending = Http::withToken($apiKey)
            // Multi-platform PostSyncer creates can take several minutes.
            // Keep the client below the queue worker's 900-second timeout.
            ->timeout(300)
            ->acceptJson()
            ->withOptions(['allow_redirects' => false])
            ->withHeaders($headers);

        if ($filePath !== null) {
            // Guzzle closes this stream when the request is released.
            $stream = fopen($filePath, 'r');
            if ($stream === false) {
                throw new PostsyncerException(
                    'Could not open the downloaded media file for upload.',
                    responseReceived: true,
                    safeToRetry: true,
                );
            }
            $mime = MimeTypes::getDefault()->guessMimeType($filePath) ?? 'application/octet-stream';
            $extension = MimeTypes::getDefault()->getExtensions($mime)[0] ?? 'bin';
            $pending = $pending->attach('file', $stream, 'media.'.$extension, ['Content-Type' => $mime]);
        }

        try {
            $response = match ($method) {
                'get' => $pending->get($url),
                'post' => $pending->post($url, $body),
                'put' => $pending->put($url, $body),
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

    /**
     * PostSyncer has returned both resource-shaped and {data: resource}
     * responses across API versions. Keep account enrichment independent of
     * that envelope; callers that need the raw response can still use the
     * existing getPost/getPostAnalytics methods.
     *
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function unwrapData(array $response): array
    {
        return is_array($response['data'] ?? null)
            ? $response['data']
            : $response;
    }

    /**
     * @param  array<mixed>  $platforms
     */
    private function needsAccountDetails(array $platforms): bool
    {
        foreach ($platforms as $platform) {
            if (! is_array($platform)) {
                continue;
            }

            $accountId = $platform['account_id'] ?? data_get($platform, 'account.id');
            if ($accountId === null || $accountId === '') {
                return true;
            }
        }

        return false;
    }

    private function hasMediaId(mixed $id): bool
    {
        return is_int($id)
            ? $id > 0
            : is_string($id) && ctype_digit($id) && (int) $id > 0;
    }
}
