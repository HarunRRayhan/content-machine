<?php

namespace App\Support\Mcp;

use RuntimeException;
use Throwable;

/**
 * Stateless MCP JSON-RPC 2.0 over HTTP. One POST is one message.
 *
 * @phpstan-type JsonRpcRequest array{jsonrpc?: string, id?: int|string|null, method?: string, params?: array<string, mixed>}
 * @phpstan-type JsonRpcResponse array{jsonrpc: string, id: int|string|null, result?: array<string, mixed>, error?: array{code: int, message: string}}
 */
final class McpServer
{
    public const PROTOCOL_VERSION = '2025-03-26';

    public function __construct(
        private readonly McpToolDispatcher $dispatcher,
    ) {}

    /**
     * @param  array<string, mixed>  $message
     * @return JsonRpcResponse|null null means a notification: HTTP 202, no body
     */
    public function handle(array $message): ?array
    {
        $method = is_string($message['method'] ?? null) ? $message['method'] : '';

        if (! array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];

        if (! is_int($id) && ! is_string($id)) {
            $id = null;
        }

        return match ($method) {
            'initialize' => $this->ok($id, [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => (object) []],
                'serverInfo' => [
                    'name' => 'content-machine',
                    'version' => '1.0.0',
                    'title' => 'Content Machine',
                ],
                'instructions' => 'Scratch Pad, ideas, videos, and posts for this workspace. Capture notes, triage them into PI/VI ideas, and list, fetch, or update videos and posts.',
            ]),
            'ping' => $this->ok($id, []),
            'tools/list' => $this->ok($id, ['tools' => McpToolCatalog::published()]),
            'tools/call' => $this->callTool($id, is_array($message['params'] ?? null) ? $message['params'] : []),
            default => [
                'jsonrpc' => '2.0',
                'id' => $id,
                'error' => ['code' => -32601, 'message' => "Method not found: {$method}"],
            ],
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return JsonRpcResponse
     */
    private function callTool(int|string|null $id, array $params): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        try {
            $result = $this->dispatcher->handle($name, $arguments);

            return $this->ok($id, [
                'content' => [
                    ['type' => 'text', 'text' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
                ],
            ]);
        } catch (RuntimeException $error) {
            return $this->ok($id, [
                'content' => [['type' => 'text', 'text' => $error->getMessage()]],
                'isError' => true,
            ]);
        } catch (Throwable $error) {
            return $this->ok($id, [
                'content' => [['type' => 'text', 'text' => $error::class.': '.$error->getMessage()]],
                'isError' => true,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     * @return JsonRpcResponse
     */
    private function ok(int|string|null $id, array $result): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'result' => $result,
        ];
    }
}
