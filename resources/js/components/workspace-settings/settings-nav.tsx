import { Link } from '@inertiajs/react';
import { edit as editGeneral } from '@/routes/settings/general';
import { edit as editPostsyncer } from '@/routes/settings/postsyncer';

const ITEMS = [
    {
        id: 'general',
        title: 'General',
        href: editGeneral(),
    },
    {
        id: 'postsyncer',
        title: 'PostSyncer',
        href: editPostsyncer(),
    },
] as const;

type SettingsNavProps = {
    active: (typeof ITEMS)[number]['id'];
};

export function SettingsNav({ active }: SettingsNavProps) {
    return (
        <div className="tabbar statustabs" role="tablist" aria-label="Settings">
            {ITEMS.map((item) => {
                const selected = item.id === active;

                return (
                    <Link
                        key={item.id}
                        role="tab"
                        aria-selected={selected}
                        href={item.href}
                        className={selected ? undefined : 'opacity-90'}
                    >
                        {item.title}
                    </Link>
                );
            })}
        </div>
    );
}
