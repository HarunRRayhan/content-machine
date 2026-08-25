import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index, show } from '@/routes/dashboard/videos';

type IdeaRow = {
    type: 'idea';
    id: number;
    human_id: string;
    title: string;
    score: number | null;
    trend: string | null;
};

type VideoRow = {
    type: 'video';
    id: number;
    human_id: string;
    number: number;
    title: string;
    status: string;
    publish_state: string;
    language: string | null;
    has_script: boolean;
    has_captions: boolean;
    has_deck: boolean;
    created_at: string | null;
};

type IndexRow = IdeaRow | VideoRow;

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedItems = {
    data: IndexRow[];
    links: PaginationLink[];
    total: number;
};

type Filters = {
    status: string;
    language: string | null;
    q: string | null;
};

type PageProps = {
    items: PaginatedItems;
    filters: Filters;
    counts: Record<string, number>;
    tabs: string[];
};

const TAB_LABELS: Record<string, string> = {
    ideation: 'Ideation',
    draft: 'Draft',
    pending: 'Pending',
    ready: 'Ready',
    recorded: 'Recorded',
    scheduled: 'Scheduled',
    posted: 'Published',
    archived: 'Archived',
    dropped: 'Dropped',
};

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

function tabQuery(filters: Filters, status: string): Record<string, string> {
    const query: Record<string, string> = { status };

    if (filters.language) {
        query.language = filters.language;
    }

    if (filters.q) {
        query.q = filters.q;
    }

    return query;
}

export default function VideosIndex({
    items,
    filters,
    counts,
    tabs,
}: PageProps) {
    const isIdeation = filters.status === 'ideation';

    function applyFilter(next: Partial<Filters>) {
        router.get(
            index.url({
                query: tabQuery(
                    {
                        status:
                            next.status !== undefined
                                ? next.status
                                : filters.status,
                        language:
                            next.language !== undefined
                                ? next.language
                                : filters.language,
                        q: next.q !== undefined ? next.q : filters.q,
                    },
                    next.status !== undefined ? next.status : filters.status,
                ),
            }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Videos" />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Heading
                    title="Videos"
                    description="Scripts, captions, and presentation decks from the content pipeline."
                />

                <div
                    role="tablist"
                    aria-label="Video pipeline status"
                    className="flex flex-wrap gap-1 border-b pb-1"
                >
                    {tabs.map((tab) => {
                        const active = filters.status === tab;

                        return (
                            <Link
                                key={tab}
                                role="tab"
                                aria-selected={active}
                                href={index.url({
                                    query: tabQuery(filters, tab),
                                })}
                                preserveScroll
                                className={cn(
                                    'inline-flex items-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition-colors',
                                    active
                                        ? 'bg-accent text-accent-foreground'
                                        : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                                )}
                            >
                                {TAB_LABELS[tab] ?? tab}
                                <Badge
                                    variant={active ? 'default' : 'secondary'}
                                    className="min-w-5 justify-center px-1.5 py-0 text-xs"
                                >
                                    {counts[tab] ?? 0}
                                </Badge>
                            </Link>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-center gap-3">
                    <Input
                        className="max-w-xs"
                        placeholder="Search title or id"
                        defaultValue={filters.q ?? ''}
                        onKeyDown={(event) => {
                            if (event.key === 'Enter') {
                                applyFilter({
                                    q:
                                        (
                                            event.target as HTMLInputElement
                                        ).value.trim() || null,
                                });
                            }
                        }}
                    />
                    {!isIdeation && (
                        <select
                            value={filters.language ?? ''}
                            onChange={(event) =>
                                applyFilter({
                                    language: event.target.value || null,
                                })
                            }
                            className="flex h-9 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                        >
                            <option value="">All languages</option>
                            <option value="bn">Bangla</option>
                            <option value="en">English</option>
                        </select>
                    )}
                </div>

                {items.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No {isIdeation ? 'ideas' : 'videos'} in this tab yet.
                    </p>
                ) : (
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="py-2 pr-4 font-medium">
                                        ID
                                    </th>
                                    <th className="py-2 pr-4 font-medium">
                                        Title
                                    </th>
                                    <th className="py-2 pr-4 font-medium">
                                        {isIdeation ? 'Score' : 'Content'}
                                    </th>
                                    <th className="py-2 pr-4 font-medium">
                                        {isIdeation ? 'Trend' : 'Status'}
                                    </th>
                                    <th className="py-2 font-medium">Open</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.data.map((row) => {
                                    if (row.type === 'idea') {
                                        return (
                                            <tr
                                                key={`idea-${row.id}`}
                                                className="border-b last:border-0"
                                            >
                                                <td className="py-2 pr-4">
                                                    <Badge variant="outline">
                                                        {row.human_id}
                                                    </Badge>
                                                </td>
                                                <td className="max-w-md py-2 pr-4 font-medium">
                                                    {row.title}
                                                </td>
                                                <td className="py-2 pr-4 text-muted-foreground">
                                                    {row.score !== null
                                                        ? `${row.score}/1000`
                                                        : '—'}
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {row.trend ? (
                                                        <Badge variant="secondary">
                                                            {row.trend}
                                                        </Badge>
                                                    ) : (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    )}
                                                </td>
                                                <td className="py-2">
                                                    <Link
                                                        href={showIdea.url(
                                                            row.id,
                                                        )}
                                                        className="text-primary underline-offset-4 hover:underline"
                                                    >
                                                        Open
                                                    </Link>
                                                </td>
                                            </tr>
                                        );
                                    }

                                    return (
                                        <tr
                                            key={`video-${row.id}`}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-2 pr-4">
                                                <Badge variant="outline">
                                                    {row.human_id}
                                                </Badge>
                                            </td>
                                            <td className="max-w-md py-2 pr-4 font-medium">
                                                {row.title}
                                            </td>
                                            <td className="py-2 pr-4">
                                                <div className="flex flex-wrap gap-1">
                                                    {row.has_script && (
                                                        <Badge variant="outline">
                                                            script
                                                        </Badge>
                                                    )}
                                                    {row.has_captions && (
                                                        <Badge variant="outline">
                                                            captions
                                                        </Badge>
                                                    )}
                                                    {row.has_deck && (
                                                        <Badge variant="outline">
                                                            deck
                                                        </Badge>
                                                    )}
                                                    {!row.has_script &&
                                                        !row.has_captions &&
                                                        !row.has_deck && (
                                                            <span className="text-muted-foreground">
                                                                —
                                                            </span>
                                                        )}
                                                </div>
                                            </td>
                                            <td className="py-2 pr-4">
                                                <div className="flex flex-wrap items-center gap-1">
                                                    <Badge variant="secondary">
                                                        {TAB_LABELS[
                                                            row.status
                                                        ] ?? row.status}
                                                    </Badge>
                                                    {[
                                                        'queued',
                                                        'running',
                                                    ].includes(
                                                        row.publish_state,
                                                    ) && (
                                                        <Badge variant="outline">
                                                            {row.publish_state}
                                                        </Badge>
                                                    )}
                                                </div>
                                            </td>
                                            <td className="py-2">
                                                <Link
                                                    href={show.url(row.id)}
                                                    className="text-primary underline-offset-4 hover:underline"
                                                >
                                                    Open
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {items.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {items.links.map((link, position) => (
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

VideosIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Videos', href: index() },
    ],
};
