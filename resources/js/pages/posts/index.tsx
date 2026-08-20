import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { home } from '@/routes/dashboard';
import { index, show } from '@/routes/dashboard/posts';

type PostSummary = {
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

type PaginatedPosts = {
    data: PostSummary[];
    links: PaginationLink[];
    total: number;
};

type PageProps = {
    posts: PaginatedPosts;
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

export default function PostsIndex({ posts }: PageProps) {
    return (
        <>
            <Head title="Posts" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Posts"
                    description="Draft posts promoted from ideas."
                />

                <div className="space-y-3">
                    {posts.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No posts yet. Promote an idea to create one.
                        </p>
                    )}

                    {posts.data.map((post) => (
                        <Link
                            key={post.id}
                            href={show.url(post.id)}
                            className="block space-y-1 rounded-lg border p-3 transition-colors hover:bg-accent"
                        >
                            <div className="flex items-center justify-between gap-2">
                                <Badge variant="outline">{post.human_id}</Badge>
                                <Badge variant="secondary">{post.status}</Badge>
                            </div>
                            <p className="font-medium">{post.title}</p>
                        </Link>
                    ))}
                </div>

                {posts.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {posts.links.map((link, position) => (
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

PostsIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Posts', href: index() },
    ],
};
