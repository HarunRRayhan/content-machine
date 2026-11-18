<?php

namespace App\Support\GoogleDrive;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Probe a Drive file the same way PostSyncer will: unauthenticated GET of
 * the constructed download URL. Never fetches the user-supplied URL itself,
 * so a paste cannot point the server at an internal host.
 */
class GoogleDriveLinkChecker
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; content-machine/1.0; +https://cm.harun.dev)';

    public function check(string $url): GoogleDriveLinkCheck
    {
        $url = trim($url);

        if ($url === '') {
            return new GoogleDriveLinkCheck(true, 'Empty.');
        }

        if (GoogleDriveLink::isFolder($url)) {
            return new GoogleDriveLinkCheck(
                false,
                'This is a Google Drive folder. Paste a file share link instead.',
            );
        }

        $link = GoogleDriveLink::parse($url);

        if ($link === null) {
            if (GoogleDriveLink::looksLikeDrive($url)) {
                return new GoogleDriveLinkCheck(
                    false,
                    'Could not read a file id from this Google Drive link.',
                );
            }

            return new GoogleDriveLinkCheck(
                true,
                'Not a Google Drive link. Saved as a direct URL.',
            );
        }

        try {
            $response = Http::timeout(12)
                ->connectTimeout(5)
                ->withHeaders([
                    'User-Agent' => self::USER_AGENT,
                    'Range' => 'bytes=0-2047',
                ])
                ->get($link->fetchUrl());
        } catch (Throwable $exception) {
            return new GoogleDriveLinkCheck(
                false,
                'Could not reach Google Drive: '.$exception->getMessage(),
                $link->fileId,
                $link->shareUrl(),
                $link->fetchUrl(),
            );
        }

        if ($this->isAccessible($response)) {
            return new GoogleDriveLinkCheck(
                true,
                'Anyone with the link can fetch this file.',
                $link->fileId,
                $link->shareUrl(),
                $link->fetchUrl(),
            );
        }

        return new GoogleDriveLinkCheck(
            false,
            'This Google Drive file is not public. Share it as Anyone with the link, then paste again.',
            $link->fileId,
            $link->shareUrl(),
            $link->fetchUrl(),
        );
    }

    private function isAccessible(Response $response): bool
    {
        $status = $response->status();

        if (in_array($status, [401, 403, 404], true)) {
            return false;
        }

        if ($status < 200 || $status >= 400) {
            return false;
        }

        $finalUrl = (string) $response->effectiveUri();
        if (str_contains(strtolower($finalUrl), 'accounts.google.com')) {
            return false;
        }

        $contentType = strtolower($response->header('Content-Type'));
        if ($this->isMediaContentType($contentType)) {
            return true;
        }

        $body = strtolower($response->body());

        if ($this->bodyLooksPrivate($body, $finalUrl)) {
            return false;
        }

        if ($this->bodyLooksLikeDownload($body, $contentType)) {
            return true;
        }

        return false;
    }

    private function isMediaContentType(string $contentType): bool
    {
        foreach (['video/', 'image/', 'audio/', 'application/octet-stream', 'application/pdf'] as $prefix) {
            if (str_starts_with($contentType, $prefix)) {
                return true;
            }
        }

        return false;
    }

    private function bodyLooksPrivate(string $body, string $finalUrl): bool
    {
        foreach ([
            'you need access',
            'request access',
            'sign in to continue',
            'accounts.google.com',
            'sorry, the file you have requested does not exist',
        ] as $marker) {
            if (str_contains($body, $marker) || str_contains(strtolower($finalUrl), $marker)) {
                return true;
            }
        }

        return false;
    }

    private function bodyLooksLikeDownload(string $body, string $contentType): bool
    {
        if (! str_contains($contentType, 'text/html') && $body === '') {
            return false;
        }

        foreach ([
            'virus scan warning',
            'uc-download-link',
            'id="download-form"',
            'confirm=t',
            'drive.usercontent.google.com/download',
        ] as $marker) {
            if (str_contains($body, $marker)) {
                return true;
            }
        }

        return false;
    }
}
