import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import WorkspaceApiTokensController, {
    revoke as revokeApiToken,
} from '@/actions/App/Http/Controllers/ApiTokens/WorkspaceApiTokensController';
import McpSetupPanel from '@/components/dashboard/mcp-setup-panel';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes/dashboard';
import { index as teamIndex } from '@/routes/dashboard/team';
import { index } from '@/routes/dashboard/team/api-tokens';

type ApiToken = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
};

type PageProps = {
    api_tokens: ApiToken[];
    newApiToken?: string | null;
    mcp_url: string;
    available_abilities: string[];
};

export default function ApiTokens({
    api_tokens,
    newApiToken,
    mcp_url,
    available_abilities,
}: PageProps) {
    // The freshly minted plaintext arrives once, as a flash prop. Hold the
    // latest value in local state (render-phase adjustment, no effect) so
    // it stays on screen across partial reloads, until the user says
    // they've saved it. A full page reload loses it — which is the point:
    // only a hash is stored server-side.
    const [lastMinted, setLastMinted] = useState<string | null>(null);
    const [dismissed, setDismissed] = useState(false);
    const [copied, setCopied] = useState(false);
    const [showForm, setShowForm] = useState(false);

    if (newApiToken && newApiToken !== lastMinted) {
        setLastMinted(newApiToken);
        setDismissed(false);
        setCopied(false);
        setShowForm(false);
    }

    const capturedToken = dismissed ? null : (newApiToken ?? lastMinted);

    const hasTokens = api_tokens.length > 0;

    return (
        <>
            <Head title="API access" />

            <div className="flex min-h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading
                        title="API access"
                        description="Bearer tokens for the JSON API and the MCP endpoint. Scoped to the team workspace you have selected. Switch teams to mint for another one."
                    />
                    {hasTokens && (
                        <Button
                            variant={showForm ? 'outline' : 'default'}
                            onClick={() => setShowForm((v) => !v)}
                        >
                            {showForm ? 'Close' : 'Create token'}
                        </Button>
                    )}
                </div>

                {capturedToken && (
                    <div className="max-w-2xl space-y-3 rounded-lg border border-amber-500/60 p-4">
                        <p className="text-sm font-medium">
                            Copy your token now
                        </p>
                        <p className="text-sm text-muted-foreground">
                            This is the only time the full token is shown. It
                            stays here until you click &quot;I&apos;ve saved
                            it&quot; or leave this page.
                        </p>
                        <div className="flex gap-2">
                            <Input
                                readOnly
                                value={capturedToken}
                                onFocus={(e) => e.currentTarget.select()}
                                className="font-mono text-xs"
                            />
                            <Button
                                variant="outline"
                                onClick={() => {
                                    navigator.clipboard.writeText(
                                        capturedToken,
                                    );
                                    setCopied(true);
                                }}
                            >
                                {copied ? 'Copied' : 'Copy'}
                            </Button>
                            <Button onClick={() => setDismissed(true)}>
                                I&apos;ve saved it
                            </Button>
                        </div>
                    </div>
                )}

                {!hasTokens && !showForm && (
                    <div className="flex flex-1 items-center justify-center">
                        <div className="max-w-md space-y-4 rounded-lg border p-8 text-center">
                            <p className="font-medium">No API tokens yet</p>
                            <p className="text-sm text-muted-foreground">
                                Mint one to let an external client (like
                                personal-content) pull and update this
                                workspace&apos;s scratchpad over the JSON API.
                            </p>
                            <Button onClick={() => setShowForm(true)}>
                                Create token
                            </Button>
                        </div>
                    </div>
                )}

                {hasTokens && (
                    <div className="space-y-2">
                        {api_tokens.map((token) => (
                            <div
                                key={token.id}
                                className="flex items-center justify-between gap-4 rounded-lg border p-3"
                            >
                                <div className="min-w-0">
                                    <p className="font-medium">{token.name}</p>
                                    <p className="mt-1 flex flex-wrap gap-1">
                                        {token.abilities.map((ability) => (
                                            <Badge
                                                key={ability}
                                                variant="outline"
                                            >
                                                {ability}
                                            </Badge>
                                        ))}
                                    </p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {token.last_used_at
                                            ? `Last used ${token.last_used_at}`
                                            : 'Never used'}
                                    </p>
                                </div>
                                <Button
                                    variant="destructive"
                                    size="sm"
                                    onClick={() =>
                                        router.delete(
                                            revokeApiToken.url({
                                                apiToken: token.id,
                                            }),
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    Revoke
                                </Button>
                            </div>
                        ))}
                    </div>
                )}

                {showForm && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <Heading
                            variant="small"
                            title="Create a token"
                            description="The full token appears above the list right after you create it — only a hash is stored, so losing it means minting a new one."
                        />

                        <Form
                            {...WorkspaceApiTokensController.store.form()}
                            resetOnSuccess
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="token-name">
                                            Token name
                                        </Label>
                                        <Input
                                            id="token-name"
                                            name="name"
                                            required
                                            placeholder="personal-content / Script Studio"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <fieldset className="space-y-2">
                                        <legend className="text-sm font-medium">
                                            Abilities
                                        </legend>
                                        {available_abilities.map((ability) => (
                                            <label
                                                key={ability}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="checkbox"
                                                    name="abilities[]"
                                                    value={ability}
                                                    defaultChecked
                                                    className="h-4 w-4 rounded border-input"
                                                />
                                                <span className="font-mono text-xs">
                                                    {ability}
                                                </span>
                                            </label>
                                        ))}
                                    </fieldset>
                                    <InputError message={errors.abilities} />

                                    <Button disabled={processing}>
                                        Create token
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                <McpSetupPanel mcpUrl={mcp_url} token={capturedToken} />

                <p className="text-sm text-muted-foreground">
                    Endpoint reference lives in{' '}
                    <a
                        href="https://github.com/HarunRRayhan/content-machine/blob/main/docs/guides/api.md"
                        target="_blank"
                        rel="noopener noreferrer"
                        className="underline hover:text-foreground"
                    >
                        docs/guides/api.md
                    </a>
                    .
                </p>
            </div>
        </>
    );
}

ApiTokens.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Team', href: teamIndex() },
        { title: 'API access', href: index() },
    ],
};
