import { Link } from '@inertiajs/react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import {
    edit as editPostsyncer,
    workspaces as editWorkspaces,
} from '@/routes/settings/postsyncer';

type PostsyncerTabsProps = {
    active: 'general' | 'workspaces';
};

const tabClassName =
    'rounded-none border-b-2 border-transparent px-3 pb-2 shadow-none data-[state=active]:border-primary data-[state=active]:bg-transparent data-[state=active]:shadow-none';

export function PostsyncerTabs({ active }: PostsyncerTabsProps) {
    return (
        <Tabs value={active}>
            <TabsList
                aria-label="PostSyncer settings"
                className="h-auto w-fit justify-start rounded-none border-b bg-transparent p-0"
            >
                <TabsTrigger value="general" asChild className={tabClassName}>
                    <Link href={editPostsyncer()}>General</Link>
                </TabsTrigger>
                <TabsTrigger
                    value="workspaces"
                    asChild
                    className={tabClassName}
                >
                    <Link href={editWorkspaces()}>Workspaces</Link>
                </TabsTrigger>
            </TabsList>
        </Tabs>
    );
}
