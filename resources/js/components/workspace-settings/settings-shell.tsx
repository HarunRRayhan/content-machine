import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';
import { SettingsNav } from '@/components/workspace-settings/settings-nav';

type SettingsShellProps = PropsWithChildren<{
    active: 'general' | 'postsyncer';
}>;

export function SettingsShell({ active, children }: SettingsShellProps) {
    return (
        <div className="studio-page flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <Heading
                title="Settings"
                description="Workspace settings for Content Machine."
            />

            <SettingsNav active={active} />

            {children}
        </div>
    );
}
