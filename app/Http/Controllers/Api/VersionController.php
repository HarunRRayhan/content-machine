<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Public deploy fingerprint: which commit this web service is running.
 * Values come from Railway's runtime variables (config/app.php); nothing here is a secret.
 */
class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var array<string, string|null> $deploy */
        $deploy = config('app.deploy');
        $commit = $deploy['commit'] ?? null;

        return response()->json([
            'commit' => $commit,
            'short' => $commit === null ? null : substr($commit, 0, 7),
            'message' => $deploy['message'] ?? null,
            'branch' => $deploy['branch'] ?? null,
            'deployment_id' => $deploy['deployment_id'] ?? null,
            'environment' => $deploy['environment'] ?? app()->environment(),
            'service' => $deploy['service'] ?? null,
            'php' => PHP_VERSION,
            'laravel' => app()->version(),
        ]);
    }
}
