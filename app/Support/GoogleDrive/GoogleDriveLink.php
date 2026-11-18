<?php

namespace App\Support\GoogleDrive;

/**
 * Parse a pasted Google Drive URL and turn it into the two forms we need:
 * a human share link for the dashboard, and a fetch URL PostSyncer can
 * download without a Google login.
 */
final readonly class GoogleDriveLink
{
    private const FILE_ID = '[A-Za-z0-9_-]{1,128}';

    public function __construct(
        public string $fileId,
    ) {}

    public static function looksLikeDrive(string $url): bool
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return in_array($host, [
            'drive.google.com',
            'docs.google.com',
            'drive.usercontent.google.com',
        ], true);
    }

    public static function isFolder(string $url): bool
    {
        return preg_match('#/drive/(?:u/\d+/)?folders/'.self::FILE_ID.'(?:[/?]|$)#', $url) === 1;
    }

    public static function folderId(string $url): ?string
    {
        $url = trim($url);

        if ($url === '' || ! self::looksLikeDrive($url)) {
            return null;
        }

        if (preg_match('#/drive/(?:u/\d+/)?folders/('.self::FILE_ID.')(?:[/?]|$)#', $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public static function parse(string $url): ?self
    {
        $url = trim($url);

        if ($url === '' || ! self::looksLikeDrive($url) || self::isFolder($url)) {
            return null;
        }

        if (preg_match('#/file/d/('.self::FILE_ID.')#', $url, $matches) === 1) {
            return new self($matches[1]);
        }

        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
            $id = $params['id'] ?? null;
            if (is_string($id) && preg_match('#^'.self::FILE_ID.'$#', $id) === 1) {
                return new self($id);
            }
        }

        return null;
    }

    public static function toFetchUrl(string $url): string
    {
        $link = self::parse($url);

        return $link?->fetchUrl() ?? $url;
    }

    public function shareUrl(): string
    {
        return 'https://drive.google.com/file/d/'.$this->fileId.'/view?usp=sharing';
    }

    public function fetchUrl(): string
    {
        return 'https://drive.usercontent.google.com/download?id='
            .rawurlencode($this->fileId)
            .'&export=download&confirm=t';
    }
}
