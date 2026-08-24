import { Form, Head, router } from '@inertiajs/react';
import WorkspaceApiTokensController, {
    revoke as revokeApiToken,
} from '@/actions/App/Http/Controllers/ApiTokens/WorkspaceApiTokensController';
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
};

const API_TOKEN_ABILITIES = [
    'scratchpad:read',
    'scratchpad:write',
    'ideas:read',
    'ideas:write',
];

export default function ApiTokens({ api_tokens }: PageProps) {
    return (
        <>
            <Head title="API access" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="API access"
                    description="Bearer tokens that let external clients read and write this workspace's scratchpad and ideas. Tokens are scoped to whichever team workspace you have selected — switch teams to mint for another one."
                />

                {api_tokens.length > 0 && (
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

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Create a token"
                        description="The full token appears once, in the toast right after you create it — only a hash is stored, so losing it means minting a new one."
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
                                    {API_TOKEN_ABILITIES.map((ability) => (
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
