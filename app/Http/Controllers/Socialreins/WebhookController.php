<?php

namespace App\Http\Controllers\Socialreins;

use App\Actions\Socialreins\ApplySocialreinsWebhookAction;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use App\Support\Socialreins\SocialreinsConfig;
use App\Support\Socialreins\WebhookSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * SocialReins' outbound webhook for a workspace's team. There is no session or CSRF token:
 * the signature over the raw body is the only credential, and nothing is read before it
 * checks out.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, int $workspace, ApplySocialreinsWebhookAction $action): JsonResponse
    {
        $found = Workspace::query()->find($workspace);
        $secret = $found !== null ? SocialreinsConfig::fromWorkspace($found)->webhookSecret() : null;

        if ($secret === null) {
            return response()->json(['error' => 'not_configured'], 404);
        }

        if (! WebhookSignature::verify($secret, $request->header('SocialReins-Signature'), $request->getContent())) {
            return response()->json(['error' => 'invalid_signature'], 401);
        }

        $event = json_decode($request->getContent(), true);

        if (! is_array($event)) {
            return response()->json(['error' => 'invalid_body'], 422);
        }

        // Not ours (yet): brain retries a non-2xx for about two days, which also covers an
        // event that arrives before CM has stored the group it was created with.
        if (! $action->handle($found->id, $event)) {
            return response()->json(['error' => 'unknown_group'], 404);
        }

        return response()->json(['ok' => true]);
    }
}
