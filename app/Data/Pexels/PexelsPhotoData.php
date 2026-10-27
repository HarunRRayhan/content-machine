<?php

namespace App\Data\Pexels;

final readonly class PexelsPhotoData
{
    public function __construct(
        public int $id,
        public string $photographer,
        public string $photographerUrl,
        public string $pexelsUrl,
        public string $previewUrl,
        public string $downloadUrl,
    ) {}

    /** @param array<string, mixed> $photo */
    public static function fromProvider(array $photo): self
    {
        $src = is_array($photo['src'] ?? null) ? $photo['src'] : [];
        $downloadUrl = $src['original'] ?? $src['large2x'] ?? $src['large'] ?? null;

        if (! is_numeric($photo['id'] ?? null)
            || ! is_string($photo['photographer'] ?? null)
            || ! is_string($photo['photographer_url'] ?? null)
            || ! is_string($photo['url'] ?? null)
            || ! is_string($src['medium'] ?? null)
            || ! is_string($downloadUrl)
        ) {
            throw new \UnexpectedValueException('Pexels returned an invalid photo record.');
        }

        return new self(
            (int) $photo['id'],
            $photo['photographer'],
            $photo['photographer_url'],
            $photo['url'],
            $src['medium'],
            $downloadUrl,
        );
    }

    /** @return array{id: int, photographer: string, photographer_url: string, pexels_url: string, preview_url: string} */
    public function toSearchArray(): array
    {
        return [
            'id' => $this->id,
            'photographer' => $this->photographer,
            'photographer_url' => $this->photographerUrl,
            'pexels_url' => $this->pexelsUrl,
            'preview_url' => $this->previewUrl,
        ];
    }
}
