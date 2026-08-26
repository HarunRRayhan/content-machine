import { Link } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    Clapperboard,
    FolderGit2,
    KeyRound,
    KeySquare,
    NotebookPen,
    Send,
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
import { index as aiProvidersIndex } from '@/routes/dashboard/ai-providers';
import { index as teamIndex } from '@/routes/dashboard/team';
import { index as apiTokensIndex } from '@/routes/dashboard/team/api-tokens';
import { edit as telegramEdit } from '@/routes/dashboard/telegram';
import { index as postsIndex } from '@/routes/posts';
import { index as scratchpadIndex } from '@/routes/scratchpad';
import { index as videosIndex } from '@/routes/videos';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
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
        title: 'AI Models',
        href: aiProvidersIndex(),
        icon: KeyRound,
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
        title: 'Telegram',
        href: telegramEdit(),
        icon: Send,
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
