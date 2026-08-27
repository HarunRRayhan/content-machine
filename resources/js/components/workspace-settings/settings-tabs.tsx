import { Link } from '@inertiajs/react';
import { edit as editPostsyncer } from '@/routes/settings/postsyncer';

const TABS = [
    {
        id: 'postsyncer',
        title: 'PostSyncer',
        href: editPostsyncer(),
    },
] as const;

type SettingsTabsProps = {
    active: (typeof TABS)[number]['id'];
};

export function SettingsTabs({ active }: SettingsTabsProps) {
    return (
        <div className="tabbar statustabs" role="tablist">
            {TABS.map((tab) => {
                const selected = tab.id === active;

                return (
                    <Link
                        key={tab.id}
                        role="tab"
                        aria-selected={selected}
                        href={tab.href}
                        className={selected ? undefined : 'opacity-90'}
                    >
                        {tab.title}
                    </Link>
                );
            })}
        </div>
    );
}
