import { Head } from '@inertiajs/react';
import { home } from '@/routes/dashboard';

type PageProps = {
    team: { name: string; slug: string } | null;
    workspace: { name: string; slug: string } | null;
};

export default function Dashboard({ team, workspace }: PageProps) {
    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
                <h1 className="text-2xl font-semibold">
                    {team ? team.name : 'No team yet'}
                </h1>
                <p className="text-muted-foreground">
                    {workspace
                        ? `Workspace: ${workspace.name}`
                        : 'No workspace resolved yet.'}
                </p>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: home(),
        },
    ],
};
