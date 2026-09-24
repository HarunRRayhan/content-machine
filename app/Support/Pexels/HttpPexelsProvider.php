<?php

namespace App\Support\Pexels;

use App\Contracts\PexelsProvider;
use App\Data\Pexels\PexelsPhotoData;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

final class HttpPexelsProvider implements PexelsProvider
{
    private const API_BASE = 'https://api.pexels.com/v1';

    private const MAX_DOWNLOAD_BYTES = 20 * 1024 * 1024;

    public function __construct(private readonly PexelsConfig $config) {}

    public function search(string $query, string $orientation, int $perPage): array
    {
        try {
            $response = $this->apiRequest()->get(self::API_BASE.'/search', [
                'query' => $query,
                'orientation' => $orientation,
                'per_page' => $perPage,
            ]);
        } catch (ConnectionException) {
            throw new RuntimeException('Pexels request failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Pexels search failed.');
        }

        $photos = $response->json('photos');
        if (! is_array($photos)) {
            throw new RuntimeException('Pexels returned an invalid search response.');
        }

        try {
            return array_values(array_map(
                fn (array $photo): PexelsPhotoData => PexelsPhotoData::fromProvider($photo),
                array_filter($photos, 'is_array'),
            ));
        } catch (Throwable) {
            throw new RuntimeException('Pexels returned an invalid search result.');
        }
    }

    public function photo(int $id): PexelsPhotoData
    {
        try {
            $response = $this->apiRequest()->get(self::API_BASE.'/photos/'.$id);
        } catch (ConnectionException) {
            throw new RuntimeException('Pexels request failed.');
        }

        if (! $response->successful() || ! is_array($response->json())) {
            throw new RuntimeException('Pexels photo lookup failed.');
        }

        try {
            $photo = PexelsPhotoData::fromProvider($response->json());
        } catch (Throwable) {
            throw new RuntimeException('Pexels returned an invalid photo record.');
        }

        if ($photo->id !== $id) {
            throw new RuntimeException('Pexels returned a different photo.');
        }

        return $photo;
    }

    public function download(PexelsPhotoData $photo): array
    {
        $url = parse_url($photo->downloadUrl);
        if (! is_array($url)
            || ($url['scheme'] ?? null) !== 'https'
            || ($url['host'] ?? null) !== 'images.pexels.com'
            || (isset($url['port']) && $url['port'] !== 443)
            || isset($url['user'])
            || isset($url['pass'])
        ) {
            throw new RuntimeException('Pexels returned an invalid image source.');
        }

        try {
            $response = Http::connectTimeout(5)
                ->timeout(30)
                ->withOptions(['stream' => true, 'allow_redirects' => false])
                ->get($photo->downloadUrl);
        } catch (ConnectionException) {
            throw new RuntimeException('Pexels image download failed.');
        }

        if (! $response->successful()) {
            throw new RuntimeException('Pexels image download failed.');
        }

        $contentLength = $response->header('Content-Length');
        if (is_numeric($contentLength) && (int) $contentLength > self::MAX_DOWNLOAD_BYTES) {
            throw new RuntimeException('Pexels image exceeds the size limit.');
        }

        $stream = $response->toPsrResponse()->getBody();
        if ($stream->getSize() > self::MAX_DOWNLOAD_BYTES) {
            throw new RuntimeException('Pexels image exceeds the size limit.');
        }

        $bytes = '';
        while (! $stream->eof()) {
            $chunk = $stream->read(min(8192, self::MAX_DOWNLOAD_BYTES + 1 - strlen($bytes)));
            if ($chunk === '') {
                break;
            }

            $bytes .= $chunk;

            if (strlen($bytes) > self::MAX_DOWNLOAD_BYTES) {
                throw new RuntimeException('Pexels image exceeds the size limit.');
            }
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (! is_string($mime) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)
            || @getimagesizefromstring($bytes) === false
        ) {
            throw new RuntimeException('Pexels returned an unsupported image.');
        }

        return ['bytes' => $bytes, 'mime' => $mime];
    }

    private function apiRequest(): PendingRequest
    {
        $key = $this->config->apiKey();
        if (! is_string($key) || $key === '') {
            throw new RuntimeException('Pexels is not configured for this workspace.');
        }

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(15)
            ->withHeader('Authorization', $key);
    }
}
