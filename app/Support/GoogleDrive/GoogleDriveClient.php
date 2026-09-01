<?php

namespace App\Support\GoogleDrive;

use App\Models\Workspace;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class GoogleDriveClient
{
    private const API_BASE = 'https://www.googleapis.com/drive/v3';

    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    private const FILE_ID_PATTERN = '/^[A-Za-z0-9_-]{1,128}$/';

    public function __construct(private readonly Workspace $workspace) {}

    /**
     * @return array{files: list<array<string, mixed>>, next_page_token: string|null, current_folder: array{id: string, name: string, parent_id: string|null}}
     */
    public function listFiles(?string $folderId = null, ?string $search = null, ?string $pageToken = null): array
    {
        $config = GoogleDriveConfig::fromWorkspace($this->workspace);
        $currentId = $folderId ?: $config->folderId() ?: 'root';

        if ($currentId !== 'root') {
            $this->assertFileId($currentId);
        }

        $currentFolder = $this->folder($currentId, $config);

        $query = [
            'q' => "'".$this->escapeQueryValue($currentId)."' in parents and trashed = false",
            'spaces' => 'drive',
            'pageSize' => 100,
            'orderBy' => 'folder,name_natural',
            'includeItemsFromAllDrives' => 'true',
            'supportsAllDrives' => 'true',
            'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,parents,webViewLink,permissions(type,role,allowFileDiscovery))',
        ];

        if (is_string($search) && trim($search) !== '') {
            $query['q'] .= " and name contains '".$this->escapeQueryValue(trim($search))."'";
        }

        if (is_string($pageToken) && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }

        $payload = $this->request('get', '/files', $query)->json();
        $rawFiles = $payload['files'] ?? [];
        $files = [];

        if (is_array($rawFiles)) {
            foreach ($rawFiles as $file) {
                if (is_array($file)) {
                    $files[] = $this->presentFile($file);
                }
            }
        }

        return [
            'files' => $files,
            'next_page_token' => is_string($payload['nextPageToken'] ?? null) ? $payload['nextPageToken'] : null,
            'current_folder' => $currentFolder,
        ];
    }

    /** @return array<string, mixed> */
    public function file(string $fileId): array
    {
        $this->assertFileId($fileId);

        $payload = $this->request('get', '/files/'.rawurlencode($fileId), [
            'supportsAllDrives' => 'true',
            'fields' => 'id,name,mimeType,modifiedTime,size,parents,webViewLink,permissions(type,role,allowFileDiscovery)',
        ])->json();

        return $this->presentFile(is_array($payload) ? $payload : []);
    }

    /** @return array<string, mixed> */
    public function makePublic(string $fileId): array
    {
        $this->assertFileId($fileId);
        $file = $this->file($fileId);

        if (! ($file['is_public'] ?? false)) {
            $this->request(
                'post',
                '/files/'.rawurlencode($fileId).'/permissions',
                [
                    'supportsAllDrives' => 'true',
                    'sendNotificationEmail' => 'false',
                ],
                ['type' => 'anyone', 'role' => 'reader'],
            );

            $file = $this->file($fileId);
        }

        return $file;
    }

    /** @return array{id: string, name: string, parent_id: string|null} */
    private function folder(string $folderId, GoogleDriveConfig $config): array
    {
        if ($folderId === 'root') {
            return ['id' => 'root', 'name' => 'My Drive', 'parent_id' => null];
        }

        $file = $this->file($folderId);

        if (($file['is_folder'] ?? false) !== true) {
            throw new GoogleDriveException('The selected Google Drive item is not a folder.');
        }

        return [
            'id' => $folderId,
            'name' => (string) ($file['name'] ?? $config->folderName() ?? 'Folder'),
            'parent_id' => is_string($file['parents'][0] ?? null) ? $file['parents'][0] : null,
        ];
    }

    private function accessToken(bool $forceRefresh = false): string
    {
        $config = GoogleDriveConfig::fromWorkspace($this->workspace);

        if (! GoogleDriveConfig::clientConfigured()) {
            throw new GoogleDriveException('Google Drive is not configured on this Content Machine deployment.');
        }

        $accessToken = $config->accessToken();

        if (! $forceRefresh && $accessToken !== null && $config->accessTokenExpiresAt() > (int) now()->timestamp + 60) {
            return $accessToken;
        }

        $refreshToken = $config->refreshToken();

        if ($refreshToken === null) {
            throw new GoogleDriveException('Connect Google Drive in Settings before browsing files.');
        }

        try {
            $response = Http::asForm()
                ->timeout(15)
                ->connectTimeout(5)
                ->post(self::TOKEN_URL, [
                    'client_id' => config('services.google_drive.client_id'),
                    'client_secret' => config('services.google_drive.client_secret'),
                    'refresh_token' => $refreshToken,
                    'grant_type' => 'refresh_token',
                ]);
        } catch (Throwable) {
            throw new GoogleDriveException('Could not reach Google Drive. Try again in a moment.');
        }

        if ($response->failed()) {
            throw new GoogleDriveException('The Google Drive connection has expired. Reconnect it in Settings.');
        }

        $token = $response->json('access_token');
        $expiresIn = $response->json('expires_in');

        if (! is_string($token) || $token === '') {
            throw new GoogleDriveException('Google did not return a usable Drive access token. Reconnect it in Settings.');
        }

        GoogleDriveConfig::storeTokens($this->workspace, [
            'access_token' => $token,
            'expires_at' => (int) now()->addSeconds(is_numeric($expiresIn) ? max(60, (int) $expiresIn) : 3600)->timestamp,
            'email' => $config->connectedEmail(),
        ]);

        return $token;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function request(string $method, string $path, array $query = [], ?array $json = null): Response
    {
        try {
            $response = $this->send($method, $path, $query, $json, $this->accessToken());

            if ($response->status() === 401) {
                $response = $this->send($method, $path, $query, $json, $this->accessToken(true));
            }
        } catch (GoogleDriveException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new GoogleDriveException('Could not reach Google Drive. Try again in a moment.');
        }

        if ($response->failed()) {
            $message = $response->json('error.message');

            if (! is_string($message) || $message === '') {
                $message = 'Google Drive returned HTTP '.$response->status().'.';
            }

            Log::warning('Google Drive API request failed.', [
                'method' => Str::upper($method),
                'path' => $path,
                'status' => $response->status(),
            ]);

            throw new GoogleDriveException($message);
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>|null  $json
     */
    private function send(string $method, string $path, array $query, ?array $json, string $accessToken): Response
    {
        $request = $this->http()->withToken($accessToken);
        $url = self::API_BASE.$path;

        if (strtolower($method) === 'post' && $query !== []) {
            $url .= '?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        return match (strtolower($method)) {
            'get' => $request->get($url, $query),
            'post' => $request->post($url, $json ?? []),
            default => throw new GoogleDriveException('Unsupported Google Drive request method.'),
        };
    }

    private function http(): PendingRequest
    {
        return Http::acceptJson()
            ->timeout(15)
            ->connectTimeout(5);
    }

    private function assertFileId(string $fileId): void
    {
        if (preg_match(self::FILE_ID_PATTERN, $fileId) !== 1) {
            throw new GoogleDriveException('That is not a valid Google Drive file id.');
        }
    }

    private function escapeQueryValue(string $value): string
    {
        return str_replace(['\\', "'"], ['\\\\', "\\'"], $value);
    }

    /**
     * @param  array<string, mixed>  $file
     * @return array<string, mixed>
     */
    private function presentFile(array $file): array
    {
        $id = is_string($file['id'] ?? null) ? $file['id'] : '';
        $permissions = is_array($file['permissions'] ?? null) ? $file['permissions'] : [];
        $isPublic = false;

        foreach ($permissions as $permission) {
            if (is_array($permission) && ($permission['type'] ?? null) === 'anyone') {
                $isPublic = in_array($permission['role'] ?? null, ['reader', 'writer', 'commenter', 'owner'], true);
                break;
            }
        }

        return [
            'id' => $id,
            'name' => is_string($file['name'] ?? null) ? $file['name'] : 'Unnamed file',
            'mime_type' => is_string($file['mimeType'] ?? null) ? $file['mimeType'] : null,
            'is_folder' => ($file['mimeType'] ?? null) === 'application/vnd.google-apps.folder',
            'is_public' => $isPublic,
            'share_url' => $id !== '' ? (new GoogleDriveLink($id))->shareUrl() : null,
            'modified_time' => is_string($file['modifiedTime'] ?? null) ? $file['modifiedTime'] : null,
            'size' => is_numeric($file['size'] ?? null) ? (int) $file['size'] : null,
            'parents' => is_array($file['parents'] ?? null) ? array_values(array_filter($file['parents'], 'is_string')) : [],
            'web_view_link' => is_string($file['webViewLink'] ?? null) ? $file['webViewLink'] : null,
        ];
    }
}
