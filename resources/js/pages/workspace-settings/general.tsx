import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { SettingsShell } from '@/components/workspace-settings/settings-shell';
import { home } from '@/routes/dashboard';
import { index as settingsIndex } from '@/routes/settings';
import { edit as editGeneral } from '@/routes/settings/general';

export default function GeneralSettings() {
    return (
        <>
            <Head title="General settings" />

            <SettingsShell>
                <Heading
                    variant="small"
                    title="General"
                    description="Workspace-wide settings that are not PostSyncer."
                />
                <div className="max-w-3xl space-y-4">
                    <p className="text-sm text-muted-foreground">
                        General workspace settings will land here. Use
                        PostSyncer for publishing.
                    </p>
                </div>
            </SettingsShell>
        </>
    );
}

GeneralSettings.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Settings', href: settingsIndex() },
        { title: 'General', href: editGeneral() },
    ],
};
