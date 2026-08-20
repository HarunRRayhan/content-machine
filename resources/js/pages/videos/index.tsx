import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { home } from '@/routes/dashboard';
import { index, show } from '@/routes/dashboard/videos';

type VideoSummary = {
    id: number;
    human_id: string;
    title: string;
    status: string;
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

type PageProps = {
    videos: PaginatedVideos;
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

export default function VideosIndex({ videos }: PageProps) {
    return (
        <>
            <Head title="Videos" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Videos"
                    description="Draft videos promoted from ideas."
                />

                <div className="space-y-3">
                    {videos.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No videos yet. Promote an idea to create one.
                        </p>
                    )}

                    {videos.data.map((video) => (
                        <Link
                            key={video.id}
                            href={show.url(video.id)}
                            className="block space-y-1 rounded-lg border p-3 transition-colors hover:bg-accent"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <Badge variant="outline">
                                    {video.human_id}
                                </Badge>
                                <Badge variant="secondary">
                                    {video.status}
                                </Badge>
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
