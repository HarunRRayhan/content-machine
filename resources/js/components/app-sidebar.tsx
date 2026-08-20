import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Clapperboard,
    FolderGit2,
    KeyRound,
    LayoutGrid,
    Lightbulb,
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
import { home } from '@/routes/dashboard';
import { index as aiProvidersIndex } from '@/routes/dashboard/ai-providers';
import { index as ideasIndex } from '@/routes/dashboard/ideas';
import { index as postsIndex } from '@/routes/dashboard/posts';
import { index as scratchpadIndex } from '@/routes/dashboard/scratchpad';
import { index as teamIndex } from '@/routes/dashboard/team';
import { edit as telegramEdit } from '@/routes/dashboard/telegram';
import { index as videosIndex } from '@/routes/dashboard/videos';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: home(),
        icon: LayoutGrid,
    },
    {
        title: 'Scratch Pad',
        href: scratchpadIndex(),
        icon: NotebookPen,
    },
    {
        title: 'Ideas',
        href: ideasIndex(),
        icon: Lightbulb,
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
        title: 'AI Providers',
        href: aiProvidersIndex(),
        icon: KeyRound,
    },
    {
        title: 'Team',
        href: teamIndex(),
        icon: Users,
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
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
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
