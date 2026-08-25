import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { home } from '@/routes/dashboard';
import { index, show } from '@/routes/dashboard/videos';

type VideoSummary = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    status: string;
    language: string | null;
    has_script: boolean;
    has_captions: boolean;
    has_deck: boolean;
    created_at: string | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedVideos = {
    data: VideoSummary[];
    links: PaginationLink[];
    total: number;
};

type Filters = {
    status: string | null;
    language: string | null;
    q: string | null;
};

type PageProps = {
    videos: PaginatedVideos;
    filters: Filters;
    statuses: string[];
};

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

export default function VideosIndex({
    videos,
    filters,
    statuses,
}: PageProps) {
    function applyFilter(next: Partial<Filters>) {
        router.get(
            index.url({
                query: {
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
            }),
            {},
            { preserveState: true, preserveScroll: true },
        );
    }

    return (
        <>
            <Head title="Videos" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Videos"
                    description="Scripts, captions, and presentation decks from the content pipeline."
                />

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
                    <select
                        value={filters.status ?? ''}
                        onChange={(event) =>
                            applyFilter({
                                status: event.target.value || null,
                            })
                        }
                        className="flex h-9 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                    >
                        <option value="">All statuses</option>
                        {statuses.map((status) => (
                            <option key={status} value={status}>
                                {status}
                            </option>
                        ))}
                    </select>
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
                </div>

                <div className="space-y-3">
                    {videos.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No videos match these filters.
                        </p>
                    )}

                    {videos.data.map((video) => (
                        <Link
                            key={video.id}
                            href={show.url(video.id)}
                            className="block space-y-2 rounded-lg border p-3 transition-colors hover:bg-accent"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">
                                    {video.human_id}
                                </Badge>
                                <Badge variant="secondary">
                                    {video.status}
                                </Badge>
                                {video.language && (
                                    <Badge variant="outline">
                                        {video.language}
                                    </Badge>
                                )}
                                {video.has_script && (
                                    <Badge variant="outline">script</Badge>
                                )}
                                {video.has_captions && (
                                    <Badge variant="outline">captions</Badge>
                                )}
                                {video.has_deck && (
                                    <Badge variant="outline">
                                        presentation
                                    </Badge>
                                )}
                            </div>
                            <p className="font-medium">{video.title}</p>
                        </Link>
                    ))}
                </div>

                {videos.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {videos.links.map((link, position) => (
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
