import { Form, Head, Link, usePage } from '@inertiajs/react';
import TeamInvitationController from '@/actions/App/Http/Controllers/TeamInvitationController';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';

type PageProps = {
    token: string;
    valid: boolean;
    teamName: string | null;
    invitedEmail: string | null;
    expired: boolean;
    accepted: boolean;
    auth?: { user: { name: string; email: string } | null };
};

export default function AcceptInvitation({
    token,
    valid,
    teamName,
    invitedEmail,
    expired,
    accepted,
}: PageProps) {
    const { auth } = usePage<PageProps>().props;
    const user = auth?.user ?? null;

    if (!valid) {
        return (
            <>
                <Head title="Invalid invitation" />
                <p className="text-center text-sm text-muted-foreground">
                    This invitation link isn't valid. Ask whoever invited you
                    for a fresh one.
                </p>
            </>
        );
    }

    if (accepted) {
        return (
            <>
                <Head title="Invitation already accepted" />
                <p className="text-center text-sm text-muted-foreground">
                    This invitation to {teamName} has already been accepted.
                </p>
            </>
        );
    }

    if (expired) {
        return (
            <>
                <Head title="Invitation expired" />
                <p className="text-center text-sm text-muted-foreground">
                    This invitation to {teamName} has expired. Ask whoever
                    invited you to send a new one.
                </p>
            </>
        );
    }

    return (
        <>
            <Head title={`Join ${teamName}`} />

            <p className="text-center text-sm text-muted-foreground">
                You've been invited to join <strong>{teamName}</strong> as{' '}
                {invitedEmail}.
            </p>

            {user ? (
                <Form
                    {...TeamInvitationController.accept.form(token)}
                    className="flex justify-center"
                >
                    {({ processing }) => (
                        <Button disabled={processing}>Accept &amp; join</Button>
                    )}
                </Form>
            ) : (
                <div className="flex justify-center gap-4">
                    <Button asChild variant="outline">
                        <Link href={login()}>Log in</Link>
                    </Button>
                    <Button asChild>
                        <Link href={register()}>Sign up</Link>
                    </Button>
                </div>
            )}
        </>
    );
}

AcceptInvitation.layout = {
    title: 'Team invitation',
    description: 'Join a team on content-machine',
};
