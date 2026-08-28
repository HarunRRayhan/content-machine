import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { home } from '@/routes/dashboard';
import { index, show } from '@/routes/dashboard/ideas';

type IdeaSummary = {
    id: number;
    human_id: string;
    kind: string;
    title: string;
    score: number | null;
    trend: string | null;
    status: string;
    created_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedIdeas = {
    data: IdeaSummary[];
    links: PaginationLink[];
    total: number;
};

type PageProps = {
    ideas: PaginatedIdeas;
    filters: {
        kind: string | null;
        status: string | null;
    };
};

const statusVariant: Record<string, 'default' | 'secondary' | 'outline'> = {
    open: 'default',
    promoted: 'secondary',
    dropped: 'outline',
};

const trendVariant: Record<string, 'default' | 'secondary'> = {
    evergreen: 'default',
    seasonal: 'secondary',
};

/**
 * Laravel's paginator labels ("&laquo; Previous", "Next &raquo;") come as
 * HTML-entity-encoded strings meant for a Blade `{!! !!}` echo. Rendering
 * them safely here just means decoding the two entities it actually uses,
 * rather than reaching for dangerouslySetInnerHTML.
 */
function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

export default function IdeasIndex({ ideas, filters }: PageProps) {
    function applyFilter(next: Partial<typeof filters>) {
        router.get(
            index.url({
                query: {
                    kind: next.kind !== undefined ? next.kind : filters.kind,
                    status:
                        next.status !== undefined
                            ? next.status
                            : filters.status,
                },
            }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Ideas" />

            <div className="flex min-h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Ideas"
                    description="Post and video ideas filed from the Scratch Pad, waiting to be promoted or dropped."
                />

                <div className="flex flex-wrap items-center gap-3">
                    <select
                        value={filters.kind ?? ''}
                        onChange={(e) =>
                            applyFilter({ kind: e.target.value || null })
                        }
                        className="flex h-9 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                    >
                        <option value="">All kinds</option>
                        <option value="post">Post</option>
                        <option value="video">Video</option>
                    </select>

                    <select
                        value={filters.status ?? ''}
                        onChange={(e) =>
                            applyFilter({ status: e.target.value || null })
                        }
                        className="flex h-9 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                    >
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="promoted">Promoted</option>
                        <option value="dropped">Dropped</option>
                    </select>
                </div>

                <div className="space-y-3">
                    {ideas.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No ideas match these filters yet.
                        </p>
                    )}

                    {ideas.data.map((idea) => (
                        <Link
                            key={idea.id}
                            href={show.url(idea.id)}
                            className="block space-y-1 rounded-lg border p-3 transition-colors hover:bg-accent"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <Badge variant="outline">
                                        {idea.human_id}
                                    </Badge>
                                    <Badge variant="outline">{idea.kind}</Badge>
                                </div>
                                <div className="flex items-center gap-2">
                                    {idea.trend && (
                                        <Badge
                                            variant={
                                                trendVariant[idea.trend] ??
                                                'secondary'
                                            }
                                        >
                                            {idea.trend}
                                        </Badge>
                                    )}
                                    <Badge
                                        variant={
                                            statusVariant[idea.status] ??
                                            'outline'
                                        }
                                    >
                                        {idea.status}
                                    </Badge>
                                </div>
                            </div>
                            <p className="font-medium">{idea.title}</p>
                            {idea.score !== null && (
                                <p className="text-sm text-muted-foreground">
                                    Score: {idea.score}/1000
                                </p>
                            )}
                        </Link>
                    ))}
                </div>

                {ideas.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {ideas.links.map((link, position) => (
                            <Button
                                key={`${link.label}-${position}`}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={link.url === null}
                                asChild={link.url !== null}
                            >
                                {link.url !== null ? (
                                    <Link href={link.url} preserveScroll>
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span>{paginationLabel(link.label)}</span>
                                )}
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

IdeasIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Ideas', href: index() },
    ],
};
