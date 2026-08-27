<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\GoogleDrive\GoogleDriveLinkChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaUrlsApiController extends Controller
{
    public function check(Request $request, GoogleDriveLinkChecker $checker): JsonResponse
    {
        $payload = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        return response()->json($checker->check($payload['url'])->toArray());
    }
}
