import { Form, Head, Link } from '@inertiajs/react';
import { HardDrive, ShieldCheck } from 'lucide-react';
import { useState } from 'react';
import GoogleDrivePicker from '@/components/google-drive-picker';
import type { GoogleDriveFile } from '@/components/google-drive-picker';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';

type PageProps = {
    clientConfigured: boolean;
    connected: boolean;
    connectedEmail: string | null;
    folderId: string | null;
    folderName: string | null;
    redirectUri: string;
};

export default function GoogleDriveSettings({
    clientConfigured,
    connected,
    connectedEmail,
    folderId,
    folderName,
    redirectUri,
}: PageProps) {
    const [selectedFolder, setSelectedFolder] = useState({
        id: folderId,
        name: folderName,
    });
    const [folderError, setFolderError] = useState<string | null>(null);

    async function saveFolder(file: GoogleDriveFile) {
        setFolderError(null);

        try {
            const response = await fetch('/settings/google-drive/folder', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document
                            .querySelector('meta[name="csrf-token"]')
                            ?.getAttribute('content') ?? '',
                },
                body: JSON.stringify({ folder_id: file.id }),
            });
            const payload = (await response.json()) as {
                folder_id?: string;
                folder_name?: string;
                message?: string;
            };

            if (!response.ok || !payload.folder_id) {
                throw new Error(
                    payload.message ?? 'Could not save this folder.',
                );
            }

            setSelectedFolder({
                id: payload.folder_id,
                name: payload.folder_name ?? file.name,
            });
        } catch (reason: unknown) {
            setFolderError(
                reason instanceof Error
                    ? reason.message
                    : 'Could not save this folder.',
            );
        }
    }

    return (
        <>
            <Head title="Google Drive" />

            <SettingsShell>
                <Heading
                    variant="small"
                    title="Google Drive"
                    description="Pick video exports from the Drive folder you use for publishing."
                />

                <div className="max-w-3xl space-y-6">
                    {!clientConfigured ? (
                        <div className="space-y-3 rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm">
                            <strong>OAuth is not configured yet.</strong>
                            <p className="text-muted-foreground">
                                Add these variables to the Content Machine
                                deployment:
                            </p>
                            <pre className="overflow-x-auto rounded bg-muted p-3 text-xs">
                                GOOGLE_DRIVE_CLIENT_ID{`\n`}
                                GOOGLE_DRIVE_CLIENT_SECRET{`\n`}
                                GOOGLE_DRIVE_REDIRECT_URI={redirectUri}
                            </pre>
                            <p className="text-muted-foreground">
                                The redirect URI must be registered on the
                                Google OAuth client.
                            </p>
                        </div>
                    ) : connected ? (
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-4">
                            <div className="flex items-center gap-3">
                                <ShieldCheck className="text-emerald-600" />
                                <div>
                                    <div className="font-medium">
                                        Drive connected
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        {connectedEmail ??
                                            'Google account connected'}
                                    </div>
                                </div>
                                <Badge variant="outline">
                                    Workspace connection
                                </Badge>
                            </div>
                            <Form
                                action="/settings/google-drive"
                                method="delete"
                            >
                                {({ processing }) => (
                                    <Button
                                        type="submit"
                                        variant="outline"
                                        disabled={processing}
                                    >
                                        Disconnect
                                    </Button>
                                )}
                            </Form>
                        </div>
                    ) : (
                        <div className="space-y-3 rounded-lg border p-4">
                            <div className="flex items-center gap-3">
                                <HardDrive />
                                <div>
                                    <div className="font-medium">
                                        Connect Google Drive
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        Content Machine will list files and can
                                        make a selected publishing file public
                                        for PostSyncer.
                                    </div>
                                </div>
                            </div>
                            <Button asChild>
                                <a href="/settings/google-drive/connect">
                                    Connect Google Drive
                                </a>
                            </Button>
                        </div>
                    )}

                    {connected && (
                        <div className="space-y-4 rounded-lg border p-4">
                            <div>
                                <h3 className="font-medium">
                                    Video content folder
                                </h3>
                                <p className="mt-1 text-sm text-muted-foreground">
                                    File pickers open here first. This does not
                                    move or upload anything.
                                </p>
                            </div>
                            <div className="flex flex-wrap items-center gap-3">
                                <GoogleDrivePicker
                                    kind="folder"
                                    initialFolderId={selectedFolder.id}
                                    onSelect={saveFolder}
                                    trigger={
                                        <Button type="button" variant="outline">
                                            Choose folder
                                        </Button>
                                    }
                                />
                                <span className="text-sm">
                                    {selectedFolder.name ??
                                        'No folder selected, starting at My Drive'}
                                </span>
                            </div>
                            {folderError && (
                                <p className="text-sm text-destructive">
                                    {folderError}
                                </p>
                            )}
                        </div>
                    )}

                    <p className="text-sm text-muted-foreground">
                        <Link className="underline" href={settingsIndex()}>
                            Back to settings
                        </Link>
                    </p>
                </div>
            </SettingsShell>
        </>
    );
}

GoogleDriveSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'Google Drive', href: '/settings/google-drive' },
    ],
};
