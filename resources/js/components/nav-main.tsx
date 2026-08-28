import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import type { NavItem } from '@/types';

function childIsActive(
    child: NavItem,
    isCurrentUrl: (href: NavItem['href']) => boolean,
    isCurrentOrParentUrl: (href: NavItem['href']) => boolean,
): boolean {
    return child.matchPrefix
        ? isCurrentOrParentUrl(child.href)
        : isCurrentUrl(child.href);
}

function NavItemWithChildren({ item }: { item: NavItem }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
    const { state, isMobile, setOpenMobile } = useSidebar();
    const [flyoutOpen, setFlyoutOpen] = useState(false);
    const closeTimer = useRef<number | null>(null);
    const children = item.children ?? [];
    const parentActive = isCurrentOrParentUrl(item.href);
    const collapsed = state === 'collapsed' && !isMobile;

    function clearCloseTimer() {
        if (closeTimer.current !== null) {
            window.clearTimeout(closeTimer.current);
            closeTimer.current = null;
        }
    }

    function openFlyout() {
        clearCloseTimer();
        setFlyoutOpen(true);
    }

    function scheduleCloseFlyout() {
        clearCloseTimer();
        closeTimer.current = window.setTimeout(() => {
            setFlyoutOpen(false);
            closeTimer.current = null;
        }, 120);
    }

    function closeMobileSheet() {
        if (isMobile) {
            setOpenMobile(false);
        }
    }

    if (collapsed) {
        return (
            <SidebarMenuItem>
                <DropdownMenu
                    open={flyoutOpen}
                    onOpenChange={setFlyoutOpen}
                    modal={false}
                >
                    <DropdownMenuTrigger asChild>
                        <SidebarMenuButton
                            isActive={parentActive}
                            aria-haspopup="menu"
                            onPointerEnter={(event) => {
                                if (event.pointerType === 'mouse') {
                                    openFlyout();
                                }
                            }}
                            onPointerLeave={(event) => {
                                if (event.pointerType === 'mouse') {
                                    scheduleCloseFlyout();
                                }
                            }}
                        >
                            {item.icon && <item.icon />}
                            <span>{item.title}</span>
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        side="right"
                        align="start"
                        sideOffset={8}
                        className="min-w-44"
                        onPointerEnter={(event) => {
                            if (event.pointerType === 'mouse') {
                                openFlyout();
                            }
                        }}
                        onPointerLeave={(event) => {
                            if (event.pointerType === 'mouse') {
                                scheduleCloseFlyout();
                            }
                        }}
                    >
                        <DropdownMenuLabel>{item.title}</DropdownMenuLabel>
                        {children.map((child) => (
                            <DropdownMenuItem key={child.title} asChild>
                                <Link href={child.href} prefetch className="flex items-center gap-2">
                                    {child.icon && (
                                        <child.icon className="size-4 shrink-0" />
                                    )}
                                    {child.title}
                                </Link>
                            </DropdownMenuItem>
                        ))}
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        );
    }

    return (
        <Collapsible defaultOpen={parentActive} className="group/collapsible">
            <SidebarMenuItem>
                <CollapsibleTrigger asChild>
                    <SidebarMenuButton
                        isActive={parentActive}
                        tooltip={{ children: item.title }}
                    >
                        {item.icon && <item.icon />}
                        <span>{item.title}</span>
                        <ChevronRight className="ml-auto transition-transform group-data-[state=open]/collapsible:rotate-90" />
                    </SidebarMenuButton>
                </CollapsibleTrigger>
                <CollapsibleContent>
                    <SidebarMenuSub>
                        {children.map((child) => (
                            <SidebarMenuSubItem key={child.title}>
                                <SidebarMenuSubButton
                                    asChild
                                    isActive={childIsActive(
                                        child,
                                        isCurrentUrl,
                                        isCurrentOrParentUrl,
                                    )}
                                >
                                    <Link
                                        href={child.href}
                                        prefetch
                                        onClick={closeMobileSheet}
                                    >
                                        {child.icon && (
                                            <child.icon className="size-4 shrink-0" />
                                        )}
                                        <span>{child.title}</span>
                                    </Link>
                                </SidebarMenuSubButton>
                            </SidebarMenuSubItem>
                        ))}
                    </SidebarMenuSub>
                </CollapsibleContent>
            </SidebarMenuItem>
        </Collapsible>
    );
}

export function NavMain({ items = [] }: { items: NavItem[] }) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <SidebarGroup className="px-2 py-0">
            <SidebarGroupLabel>Platform</SidebarGroupLabel>
            <SidebarMenu>
                {items.map((item) => {
                    if (item.children && item.children.length > 0) {
                        return (
                            <NavItemWithChildren key={item.title} item={item} />
                        );
                    }

                    return (
                        <SidebarMenuItem key={item.title}>
                            <SidebarMenuButton
                                asChild
                                isActive={
                                    item.matchPrefix
                                        ? isCurrentOrParentUrl(item.href)
                                        : isCurrentUrl(item.href)
                                }
                                tooltip={{ children: item.title }}
                            >
                                <Link href={item.href} prefetch>
                                    {item.icon && <item.icon />}
                                    <span>{item.title}</span>
                                </Link>
                            </SidebarMenuButton>
                        </SidebarMenuItem>
                    );
                })}
            </SidebarMenu>
        </SidebarGroup>
    );
}
