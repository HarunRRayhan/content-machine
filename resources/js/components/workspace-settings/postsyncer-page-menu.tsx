import { Link } from '@inertiajs/react';
import { ChevronDown } from 'lucide-react';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    edit as editPostsyncer,
    workspaces as editWorkspaces,
} from '@/routes/settings/postsyncer';

type PostsyncerPageMenuProps = {
    active: 'api' | 'workspaces';
};

export function PostsyncerPageMenu({ active }: PostsyncerPageMenuProps) {
    const label = active === 'api' ? 'API' : 'Workspaces';

    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm">
                    {label}
                    <ChevronDown />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                <DropdownMenuItem asChild>
                    <Link href={editPostsyncer()}>API</Link>
                </DropdownMenuItem>
                <DropdownMenuItem asChild>
                    <Link href={editWorkspaces()}>Workspaces</Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
