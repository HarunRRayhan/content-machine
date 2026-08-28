import { Link } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    Clapperboard,
    FolderGit2,
    Image,
    ImagePlay,
    Film,
    Images,
    KeySquare,
    LayoutDashboard,
    NotebookPen,
    Settings,
    SquarePen,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { index as calendarIndex } from '@/routes/calendar';
import { home } from '@/routes/dashboard';
import { index as mediaIndex, images as mediaImages, videos as mediaVideos, gifs as mediaGifs } from '@/routes/media';
import { index as teamIndex } from '@/routes/dashboard/team';
import { index as apiTokensIndex } from '@/routes/dashboard/team/api-tokens';
import { index as postsIndex } from '@/routes/posts';
import { index as scratchpadIndex } from '@/routes/scratchpad';
import { index as settingsIndex } from '@/routes/settings';
import { index as aiProvidersIndex } from '@/routes/settings/ai-providers';
import { edit as editGeneral } from '@/routes/settings/general';
import { edit as editPostsyncer } from '@/routes/settings/postsyncer';
import { edit as telegramEdit } from '@/routes/settings/telegram';
import { index as videosIndex } from '@/routes/videos';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: home(),
        icon: LayoutDashboard,
    },
    {
        title: 'Scratch Pad',
        href: scratchpadIndex(),
        icon: NotebookPen,
    },
    {
        title: 'Posts',
        href: postsIndex(),
        icon: SquarePen,
    },
    {
        title: 'Videos',
        href: videosIndex(),
        icon: Clapperboard,
    },
    {
        title: 'Calendar',
        href: calendarIndex(),
        icon: CalendarDays,
    },
    {
        title: 'Media',
        href: mediaIndex(),
        icon: Images,
        matchPrefix: true,
        children: [
            {
                title: 'Images',
                href: mediaImages(),
                icon: Image,
            },
            {
                title: 'Videos',
                href: mediaVideos(),
                icon: Film,
            },
            {
                title: 'GIFs',
                href: mediaGifs(),
                icon: ImagePlay,
            },
        ],
    },
    {
        title: 'Team',
        href: teamIndex(),
        icon: Users,
    },
    {
        title: 'API access',
        href: apiTokensIndex(),
        icon: KeySquare,
    },
    {
        title: 'Settings',
        href: settingsIndex(),
        icon: Settings,
        matchPrefix: true,
        children: [
            {
                title: 'General',
                href: editGeneral(),
            },
            {
                title: 'PostSyncer',
                href: editPostsyncer(),
                matchPrefix: true,
            },
            {
                title: 'AI Models',
                href: aiProvidersIndex(),
            },
            {
                title: 'Telegram',
                href: telegramEdit(),
            },
        ],
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/HarunRRayhan/content-machine',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/HarunRRayhan/content-machine/blob/main/docs/README.md',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
