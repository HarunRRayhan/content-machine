<?php

namespace App\Support\Mcp;

/**
 * Browser-hosted agents (Claude.ai, ChatGPT) preflight this endpoint.
 * Keep the allow-list tight: the methods and headers MCP clients send.
 */
final class McpCors
{
    /**
     * @return array<string, string>
     */
    public static function headers(): array
    {
        return [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'POST, OPTIONS',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type, Accept, MCP-Protocol-Version, Mcp-Session-Id',
            'Access-Control-Max-Age' => '86400',
        ];
    }
}
