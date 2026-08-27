<?php

namespace App\Support\GoogleDrive;

final readonly class GoogleDriveLinkCheck
{
    public function __construct(
        public bool $ok,
        public string $message,
        public ?string $fileId = null,
        public ?string $shareUrl = null,
        public ?string $fetchUrl = null,
    ) {}

    /**
     * @return array{accessible: bool, message: string, file_id: ?string, share_url: ?string, fetch_url: ?string}
     */
    public function toArray(): array
    {
        return [
            'accessible' => $this->ok,
            'message' => $this->message,
            'file_id' => $this->fileId,
            'share_url' => $this->shareUrl,
            'fetch_url' => $this->fetchUrl,
        ];
    }
}
