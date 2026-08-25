import { Head, Link, router } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { home } from '@/routes/dashboard';
import { index, show } from '@/routes/dashboard/posts';

type PostSummary = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    status: string;
    language: string | null;
    platforms: string[];
    has_captions: boolean;
    has_body: boolean;
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

type Filters = {
    status: string | null;
    language: string | null;
    q: string | null;
};

type PageProps = {
    posts: PaginatedPosts;
    filters: Filters;
    statuses: string[];
};

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

export default function PostsIndex({ posts, filters, statuses }: PageProps) {
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
            <Head title="Posts" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Posts"
                    description="Bodies, per-platform captions, and images from the content pipeline."
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
                    {posts.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            No posts match these filters.
                        </p>
                    )}

                    {posts.data.map((post) => (
                        <Link
                            key={post.id}
                            href={show.url(post.id)}
                            className="block space-y-2 rounded-lg border p-3 transition-colors hover:bg-accent"
                        >
                            <div className="flex flex-wrap items-center gap-2">
                                <Badge variant="outline">{post.human_id}</Badge>
                                <Badge variant="secondary">{post.status}</Badge>
                                {post.language && (
                                    <Badge variant="outline">
                                        {post.language}
                                    </Badge>
                                )}
                                {post.has_captions && (
                                    <Badge variant="outline">captions</Badge>
                                )}
                                {post.platforms.slice(0, 3).map((platform) => (
                                    <Badge key={platform} variant="outline">
                                        {platform}
                                    </Badge>
                                ))}
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
