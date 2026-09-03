<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Settings\Concerns\AuthorizesWorkspaceSettings;
use App\Support\GoogleDrive\GoogleDriveClient;
use App\Support\GoogleDrive\GoogleDriveConfig;
use App\Support\GoogleDrive\GoogleDriveException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class GoogleDriveController extends Controller
{
    use AuthorizesWorkspaceSettings;

    public function edit(Request $request): Response
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);
        $config = GoogleDriveConfig::fromWorkspace($workspace);

        return Inertia::render('workspace-settings/google-drive', [
            'clientConfigured' => GoogleDriveConfig::clientConfigured(),
            'connected' => $config->isConnected(),
            'connectedEmail' => $config->connectedEmail(),
            'folderId' => $config->folderId(),
            'folderName' => $config->folderName(),
            'redirectUri' => GoogleDriveConfig::redirectUri(),
        ]);
    }

    public function connect(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        if (! GoogleDriveConfig::clientConfigured()) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Google Drive OAuth is not configured on this deployment.'),
            ]);

            return to_route('settings.google-drive.edit');
        }

        $state = Str::random(64);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $request->session()->put([
            'google_drive_oauth_state' => $state,
            'google_drive_oauth_verifier' => $verifier,
            'google_drive_oauth_workspace_id' => $workspace->id,
        ]);

        $query = http_build_query([
            'client_id' => config('services.google_drive.client_id'),
            'redirect_uri' => GoogleDriveConfig::redirectUri(),
            'response_type' => 'code',
            'scope' => GoogleDriveConfig::SCOPE,
            'access_type' => 'offline',
            'prompt' => 'consent',
            'include_granted_scopes' => 'true',
            'state' => $state,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);

        $state = $request->session()->pull('google_drive_oauth_state');
        $verifier = $request->session()->pull('google_drive_oauth_verifier');
        $workspaceId = $request->session()->pull('google_drive_oauth_workspace_id');

        if (! is_string($state) || ! hash_equals($state, (string) $request->query('state')) || (int) $workspaceId !== $workspace->id) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('The Google Drive authorization expired. Start the connection again.'),
            ]);

            return to_route('settings.google-drive.edit');
        }

        if ($request->filled('error')) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Google Drive authorization was cancelled.'),
            ]);

            return to_route('settings.google-drive.edit');
        }

        $code = $request->query('code');

        if (! is_string($verifier) || ! is_string($code) || $code === '') {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Google did not return an authorization code.'),
            ]);

            return to_route('settings.google-drive.edit');
        }

        try {
            $tokenResponse = Http::asForm()
                ->timeout(15)
                ->connectTimeout(5)
                ->post('https://oauth2.googleapis.com/token', [
                    'code' => $code,
                    'client_id' => config('services.google_drive.client_id'),
                    'client_secret' => config('services.google_drive.client_secret'),
                    'redirect_uri' => GoogleDriveConfig::redirectUri(),
                    'grant_type' => 'authorization_code',
                    'code_verifier' => $verifier,
                ]);

            if ($tokenResponse->failed()) {
                throw new GoogleDriveException('Google rejected the Drive authorization. Try connecting again.');
            }

            $accessToken = $tokenResponse->json('access_token');
            $refreshToken = $tokenResponse->json('refresh_token');
            $expiresIn = $tokenResponse->json('expires_in');
            $existing = GoogleDriveConfig::fromWorkspace($workspace);

            if (! is_string($accessToken) || $accessToken === '') {
                throw new GoogleDriveException('Google did not return a usable Drive access token.');
            }

            if (! is_string($refreshToken) || $refreshToken === '') {
                $refreshToken = $existing->refreshToken();
            }

            if ($refreshToken === null) {
                throw new GoogleDriveException('Google did not return a refresh token. Disconnect Drive and connect it again.');
            }

            $about = Http::withToken($accessToken)
                ->timeout(15)
                ->connectTimeout(5)
                ->get('https://www.googleapis.com/drive/v3/about', [
                    'fields' => 'user(emailAddress,displayName)',
                ]);
            $email = $about->json('user.emailAddress');

            GoogleDriveConfig::storeTokens($workspace, [
                'access_token' => $accessToken,
                'expires_at' => (int) now()->addSeconds(is_numeric($expiresIn) ? max(60, (int) $expiresIn) : 3600)->timestamp,
                'refresh_token' => $refreshToken,
                'email' => is_string($email) ? $email : $existing->connectedEmail(),
            ]);
        } catch (GoogleDriveException $exception) {
            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return to_route('settings.google-drive.edit');
        } catch (Throwable) {
            Inertia::flash('toast', [
                'type' => 'error',
                'message' => __('Could not reach Google Drive. Try again in a moment.'),
            ]);

            return to_route('settings.google-drive.edit');
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Google Drive connected. Choose the folder that contains your video exports.'),
        ]);

        return to_route('settings.google-drive.edit');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);
        GoogleDriveConfig::disconnect($workspace);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Google Drive disconnected.')]);

        return to_route('settings.google-drive.edit');
    }

    public function files(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);
        $validated = $request->validate([
            'folder_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
            'q' => ['nullable', 'string', 'max:200'],
            'page_token' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            return response()->json((new GoogleDriveClient($workspace))->listFiles(
                $validated['folder_id'] ?? null,
                $validated['q'] ?? null,
                $validated['page_token'] ?? null,
            ));
        } catch (GoogleDriveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function setFolder(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);
        $validated = $request->validate([
            'folder_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
        ]);

        try {
            if ($validated['folder_id'] === 'root') {
                GoogleDriveConfig::storeFolder($workspace, 'root', 'My Drive');

                return response()->json([
                    'folder_id' => 'root',
                    'folder_name' => 'My Drive',
                ]);
            }

            $folder = (new GoogleDriveClient($workspace))->file($validated['folder_id']);

            if (($folder['is_folder'] ?? false) !== true) {
                return response()->json(['message' => 'Choose a folder, not a file.'], 422);
            }

            GoogleDriveConfig::storeFolder($workspace, (string) $folder['id'], (string) $folder['name']);

            return response()->json([
                'folder_id' => $folder['id'],
                'folder_name' => $folder['name'],
            ]);
        } catch (GoogleDriveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    public function makePublic(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
        $this->authorizeWorkspaceAdmin($request, $workspace);
        $validated = $request->validate([
            'file_id' => ['required', 'string', 'regex:/^[A-Za-z0-9_-]{1,128}$/'],
        ]);

        try {
            $file = (new GoogleDriveClient($workspace))->makePublic($validated['file_id']);

            return response()->json(['file' => $file]);
        } catch (GoogleDriveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }
}
