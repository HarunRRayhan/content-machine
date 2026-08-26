<?php

namespace App\Http\Controllers\Mcp;

use App\Http\Controllers\Controller;
use App\Support\Mcp\McpCors;
use App\Support\Mcp\McpServer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Streamable HTTP MCP endpoint at POST /mcp. Auth is the same workspace
 * bearer token as /api/v1. One JSON-RPC message per request.
 */
class McpController extends Controller
{
    public function preflight(): Response
    {
        return response('', 204, McpCors::headers());
    }

    public function handle(Request $request, McpServer $server): JsonResponse|Response
    {
        $payload = $request->json()->all();

        if ($payload === [] || ! isset($payload['method'])) {
            return $this->reply($request, [
                'jsonrpc' => '2.0',
                'id' => null,
                'error' => ['code' => -32600, 'message' => 'Invalid Request'],
            ], 400);
        }

        $response = $server->handle($payload);

        if ($response === null) {
            return response('', 202, McpCors::headers());
        }

        return $this->reply($request, $response);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function reply(Request $request, array $payload, int $status = 200): JsonResponse|Response
    {
        $accept = $request->header('Accept', 'application/json');
        $headers = McpCors::headers();

        if (str_contains($accept, 'text/event-stream') && ! str_contains($accept, 'application/json')) {
            $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return response("event: message\ndata: {$encoded}\n\n", $status, [
                ...$headers,
                'Content-Type' => 'text/event-stream',
                'Cache-Control' => 'no-cache',
            ]);
        }

        return response()->json($payload, $status, $headers);
    }
}
