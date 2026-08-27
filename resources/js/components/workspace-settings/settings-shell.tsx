import type { PropsWithChildren } from 'react';
import Heading from '@/components/heading';

export function SettingsShell({ children }: PropsWithChildren) {
    return (
        <div className="studio-page flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
            <Heading
                title="Settings"
                description="Workspace settings for Content Machine."
            />

            {children}
        </div>
    );
}
