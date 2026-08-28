import { Form, Head } from '@inertiajs/react';
import TeamController from '@/actions/App/Http/Controllers/TeamController';
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

type PageProps = {
    team: { name: string; slug: string };
    members: Member[];
    invitations: Invitation[];
};

export default function Team({ team, members, invitations }: PageProps) {
    return (
        <>
            <Head title="Team" />

            <div className="flex min-h-full flex-1 flex-col gap-8 rounded-xl p-4">
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
