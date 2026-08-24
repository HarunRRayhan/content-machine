import { Form, Head, router } from '@inertiajs/react';
import TeamController, {
    revokeApiToken,
} from '@/actions/App/Http/Controllers/TeamController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { home } from '@/routes/dashboard';
import { index } from '@/routes/dashboard/team';

type Member = {
    id: number;
    name: string;
    email: string;
    role: string;
};

type Invitation = {
    id: number;
    email: string;
    role: string;
    expired: boolean;
    url: string;
};

type ApiToken = {
    id: number;
    name: string;
    abilities: string[];
    last_used_at: string | null;
    created_at: string | null;
};

type PageProps = {
    team: { name: string; slug: string };
    members: Member[];
    invitations: Invitation[];
    api_tokens: ApiToken[];
};

const API_TOKEN_ABILITIES = [
    'scratchpad:read',
    'scratchpad:write',
    'ideas:read',
    'ideas:write',
];

export default function Team({
    team,
    members,
    invitations,
    api_tokens,
}: PageProps) {
    return (
        <>
            <Head title="Team" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title={`${team.name} members`}
                    description="Everyone with access to this team, plus anyone still waiting on an invite."
                />

                <div className="space-y-3">
                    {members.map((member) => (
                        <div
                            key={member.id}
                            className="flex items-center justify-between rounded-lg border p-3"
                        >
                            <div>
                                <p className="font-medium">{member.name}</p>
                                <p className="text-sm text-muted-foreground">
                                    {member.email}
                                </p>
                            </div>
                            <Badge variant="secondary">{member.role}</Badge>
                        </div>
                    ))}
                </div>

                {invitations.length > 0 && (
                    <div className="space-y-3">
                        <Heading variant="small" title="Pending invitations" />

                        {invitations.map((invitation) => (
                            <div
                                key={invitation.id}
                                className="space-y-2 rounded-lg border p-3"
                            >
                                <div className="flex items-center justify-between">
                                    <div>
                                        <p className="font-medium">
                                            {invitation.email}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            Invited as {invitation.role}
                                        </p>
                                    </div>
                                    {invitation.expired && (
                                        <Badge variant="destructive">
                                            Expired
                                        </Badge>
                                    )}
                                </div>

                                <Input
                                    readOnly
                                    value={invitation.url}
                                    onFocus={(e) => e.currentTarget.select()}
                                    className="font-mono text-xs"
                                />
                            </div>
                        ))}
                    </div>
                )}

                <div className="max-w-md space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="Invite someone"
                        description="No email is sent yet, copy the link that appears above once it's created."
                    />

                    <Form
                        {...TeamController.storeInvitation.form()}
                        resetOnSuccess
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="email">Email</Label>
                                    <Input
                                        id="email"
                                        type="email"
                                        name="email"
                                        required
                                        placeholder="teammate@example.com"
                                    />
                                    <InputError message={errors.email} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="role">Role</Label>
                                    <select
                                        id="role"
                                        name="role"
                                        defaultValue="member"
                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                    >
                                        <option value="member">Member</option>
                                        <option value="admin">Admin</option>
                                        <option value="owner">Owner</option>
                                    </select>
                                    <InputError message={errors.role} />
                                </div>

                                <Button disabled={processing}>
                                    Send invitation
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Heading
                        variant="small"
                        title="API tokens"
                        description="Bearer tokens for external clients (personal-content, MCP). The full token is shown once, in the toast right after you create it — only a hash is stored."
                    />

                    {api_tokens.length > 0 && (
                        <div className="space-y-2">
                            {api_tokens.map((token) => (
                                <div
                                    key={token.id}
                                    className="flex items-center justify-between gap-4 rounded-lg border p-3"
                                >
                                    <div className="min-w-0">
                                        <p className="font-medium">
                                            {token.name}
                                        </p>
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

                    <Form
                        {...TeamController.storeApiToken.form()}
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
            </div>
        </>
    );
}

Team.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Team', href: index() },
    ],
};
