<?php

namespace App\Http\Controllers;

use App\Support\GoogleDrive\GoogleDriveLinkChecker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MediaUrlCheckController extends Controller
{
    public function __invoke(Request $request, GoogleDriveLinkChecker $checker): JsonResponse
    {
        $payload = $request->validate([
            'url' => ['required', 'string', 'url', 'max:2048'],
        ]);

        return response()->json($checker->check($payload['url'])->toArray());
    }
}
