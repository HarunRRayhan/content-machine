import { useState } from 'react';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';

type AgentId =
    | 'claude'
    | 'cursor'
    | 'codex'
    | 'chatgpt'
    | 'opencode'
    | 'commandcode'
    | 'custom';

type Agent = {
    id: AgentId;
    label: string;
    blurb: string;
    steps: string[];
    snippet: (url: string, token: string) => string;
    language: string;
};

type Props = {
    mcpUrl: string;
    token?: string | null;
};

const AGENTS: Agent[] = [
    {
        id: 'claude',
        label: 'Claude',
        blurb: 'Claude Code, Claude.ai Connectors, and Claude Desktop all take the same remote URL plus your bearer token.',
        steps: [
            'Mint a token above if you do not already have one.',
            'Claude Code: run the command below, then approve the server when prompted.',
            'Claude.ai: Settings → Connectors → Add custom connector. Paste the URL and Authorization header.',
            'Claude Desktop: merge the JSON into ~/Library/Application Support/Claude/claude_desktop_config.json (macOS).',
        ],
        language: 'bash',
        snippet: (url, token) =>
            [
                `# Claude Code`,
                `claude mcp add --transport http content-machine ${url} \\`,
                `  --header "Authorization: Bearer ${token}"`,
                ``,
                `# claude_desktop_config.json / project .mcp.json`,
                `{`,
                `  "mcpServers": {`,
                `    "content-machine": {`,
                `      "type": "http",`,
                `      "url": "${url}",`,
                `      "headers": {`,
                `        "Authorization": "Bearer ${token}"`,
                `      }`,
                `    }`,
                `  }`,
                `}`,
            ].join('\n'),
    },
    {
        id: 'cursor',
        label: 'Cursor',
        blurb: 'Add a project or global MCP server. Cursor Settings → Tools & MCP, or drop this into .cursor/mcp.json.',
        steps: [
            'Mint a token above.',
            'Cursor Settings → Tools & MCP → New MCP Server, or create .cursor/mcp.json in the repo.',
            'Paste the JSON. For a token you keep in the environment, use Bearer ${env:CONTENT_MACHINE_TOKEN}.',
            'Reload MCP. The content-machine tools should appear in the agent tool list.',
        ],
        language: 'json',
        snippet: (url, token) =>
            JSON.stringify(
                {
                    mcpServers: {
                        'content-machine': {
                            url,
                            headers: {
                                Authorization: `Bearer ${token}`,
                            },
                        },
                    },
                },
                null,
                2,
            ),
    },
    {
        id: 'codex',
        label: 'Codex',
        blurb: 'OpenAI Codex CLI and the Codex IDE extension read ~/.codex/config.toml. A url key means Streamable HTTP.',
        steps: [
            'Mint a token above and export it: export CONTENT_MACHINE_TOKEN=…',
            'Add the table below to ~/.codex/config.toml, or a trusted project .codex/config.toml.',
            'Restart Codex. Confirm with: codex mcp list',
        ],
        language: 'toml',
        snippet: (url, token) =>
            [
                `[mcp_servers.content-machine]`,
                `url = "${url}"`,
                `bearer_token_env_var = "CONTENT_MACHINE_TOKEN"`,
                `enabled = true`,
                ``,
                `# Or pin the token in the file (avoid committing this):`,
                `# http_headers = { Authorization = "Bearer ${token}" }`,
            ].join('\n'),
    },
    {
        id: 'chatgpt',
        label: 'ChatGPT',
        blurb: 'ChatGPT custom connectors and developer-mode MCP apps talk to a remote Streamable HTTP server.',
        steps: [
            'Mint a token above.',
            'In ChatGPT: Settings → Apps & Connectors (or Developer mode) → create a custom MCP connector.',
            `Server URL: the endpoint below. Auth: Bearer token.`,
            'Save, then start a new chat and enable the Content Machine connector.',
        ],
        language: 'text',
        snippet: (url, token) =>
            [
                `Server URL`,
                url,
                ``,
                `Authorization`,
                `Bearer ${token}`,
                ``,
                `Transport`,
                `Streamable HTTP (POST JSON-RPC)`,
            ].join('\n'),
    },
    {
        id: 'opencode',
        label: 'Open Code',
        blurb: 'OpenCode remote MCP lives in opencode.json. Set oauth to false so it uses the header instead of a browser login.',
        steps: [
            'Mint a token above.',
            'Edit ~/.config/opencode/opencode.json or the project opencode.json.',
            'Merge the mcp block below. Restart OpenCode.',
        ],
        language: 'json',
        snippet: (url, token) =>
            JSON.stringify(
                {
                    $schema: 'https://opencode.ai/config.json',
                    mcp: {
                        'content-machine': {
                            type: 'remote',
                            url,
                            enabled: true,
                            oauth: false,
                            headers: {
                                Authorization: `Bearer ${token}`,
                            },
                        },
                    },
                },
                null,
                2,
            ),
    },
    {
        id: 'commandcode',
        label: 'Command Code',
        blurb: 'Command Code adds remote MCP with cmd mcp add. Flags go before the server name.',
        steps: [
            'Mint a token above.',
            'Run the command below (user scope so every project sees it).',
            'Inside a session, /mcp should list content-machine as connected.',
        ],
        language: 'bash',
        snippet: (url, token) =>
            [
                `cmd mcp add --transport http --scope user \\`,
                `  --header "Authorization: Bearer ${token}" \\`,
                `  content-machine ${url}`,
                ``,
                `# Or JSON`,
                `cmd mcp add-json content-machine '{`,
                `  "type": "http",`,
                `  "url": "${url}",`,
                `  "headers": { "Authorization": "Bearer ${token}" }`,
                `}'`,
            ].join('\n'),
    },
    {
        id: 'custom',
        label: 'Custom',
        blurb: 'Any MCP client that speaks Streamable HTTP. One JSON-RPC message per POST. Same bearer token as /api/v1.',
        steps: [
            'Mint a token above.',
            'POST JSON-RPC to the URL with Authorization: Bearer and Accept: application/json, text/event-stream.',
            'Call initialize, then tools/list, then tools/call. Notifications (no id) get HTTP 202.',
        ],
        language: 'bash',
        snippet: (url, token) =>
            [
                `curl -sS ${url} \\`,
                `  -H "Authorization: Bearer ${token}" \\`,
                `  -H "Content-Type: application/json" \\`,
                `  -H "Accept: application/json, text/event-stream" \\`,
                `  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-03-26","capabilities":{},"clientInfo":{"name":"custom","version":"1.0"}}}'`,
                ``,
                `curl -sS ${url} \\`,
                `  -H "Authorization: Bearer ${token}" \\`,
                `  -H "Content-Type: application/json" \\`,
                `  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'`,
            ].join('\n'),
    },
];

function CopyBlock({ text, language }: { text: string; language: string }) {
    const [copied, setCopied] = useState(false);

    return (
        <div className="relative">
            <pre className="overflow-x-auto rounded-md border bg-muted/40 p-3 font-mono text-xs leading-relaxed whitespace-pre">
                <code data-language={language}>{text}</code>
            </pre>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="absolute top-2 right-2"
                onClick={() => {
                    void navigator.clipboard.writeText(text);
                    setCopied(true);
                    window.setTimeout(() => setCopied(false), 1500);
                }}
            >
                {copied ? 'Copied' : 'Copy'}
            </Button>
        </div>
    );
}

export default function McpSetupPanel({ mcpUrl, token }: Props) {
    const resolvedToken = token && token !== '' ? token : 'cm_YOUR_TOKEN';

    return (
        <section className="space-y-4 rounded-lg border p-4">
            <Heading
                variant="small"
                title="Connect with MCP"
                description="Remote Streamable HTTP at the URL below. Same workspace token as the JSON API. Scratch pad, ideas, videos, and posts tools show up in the agent you pick."
            />

            <p className="font-mono text-xs break-all text-muted-foreground">
                {mcpUrl}
            </p>

            <Tabs defaultValue="claude">
                <TabsList className="flex h-auto w-full flex-wrap justify-start gap-1 bg-transparent p-0">
                    {AGENTS.map((agent) => (
                        <TabsTrigger
                            key={agent.id}
                            value={agent.id}
                            className="rounded-md border px-3 py-1.5 data-[state=active]:bg-background"
                        >
                            {agent.label}
                        </TabsTrigger>
                    ))}
                </TabsList>

                {AGENTS.map((agent) => (
                    <TabsContent
                        key={agent.id}
                        value={agent.id}
                        className="space-y-3 pt-3"
                    >
                        <p className="text-sm text-muted-foreground">
                            {agent.blurb}
                        </p>
                        <ol className="list-decimal space-y-1 pl-5 text-sm">
                            {agent.steps.map((step) => (
                                <li key={step}>{step}</li>
                            ))}
                        </ol>
                        <CopyBlock
                            text={agent.snippet(mcpUrl, resolvedToken)}
                            language={agent.language}
                        />
                    </TabsContent>
                ))}
            </Tabs>
        </section>
    );
}
