<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\GoogleDrive\GoogleDriveClient;
use App\Support\GoogleDrive\GoogleDriveException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GoogleDriveApiController extends Controller
{
    public function files(Request $request): JsonResponse
    {
        $workspace = $this->currentWorkspace();
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

    public function makePublic(string $fileId): JsonResponse
    {
        $workspace = $this->currentWorkspace();

        try {
            return response()->json([
                'file' => (new GoogleDriveClient($workspace))->makePublic($fileId),
            ]);
        } catch (GoogleDriveException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }
    }

    private function currentWorkspace(): Workspace
    {
        $workspace = Workspace::current();

        abort_if($workspace === null, 404, 'No current workspace.');

        return $workspace;
    }
}
