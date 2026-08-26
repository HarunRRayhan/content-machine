import { Head, Link, router } from '@inertiajs/react';
import {
    PLATFORM_META,
    POST_STATUS_LABELS,
    normalizePlatformKey,
    studioPostStatus,
} from '@/lib/platform-meta';
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
    statusCounts: Record<string, number>;
};

const POST_TABS = ['draft', 'scheduled', 'posted', 'archived'] as const;

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

export default function PostsIndex({ posts, filters, statusCounts }: PageProps) {
    const activeTab = (filters.status ?? 'draft') as (typeof POST_TABS)[number];

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

            <div className="studio-page flex h-full flex-1 flex-col gap-2 p-4">
                <h2 className="home-h">All posts</h2>

                <div className="search-row">
                    <input
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
                </div>

                <div className="tabbar statustabs" role="tablist">
                    {POST_TABS.map((tab) => (
                        <button
                            key={tab}
                            type="button"
                            role="tab"
                            aria-selected={activeTab === tab}
                            onClick={() => applyFilter({ status: tab })}
                        >
                            {POST_STATUS_LABELS[tab]}
                            <span className="tabn">
                                {statusCounts[tab] ?? 0}
                            </span>
                        </button>
                    ))}
                </div>

                {posts.data.length === 0 ? (
                    <p className="empty">No posts in this stage yet.</p>
                ) : (
                    <div className="vtable-wrap">
                        <table className="vtable">
                            <thead>
                                <tr>
                                    <th>#</th>
                                    <th>Title</th>
                                    <th>Platforms</th>
                                    <th>Status</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {posts.data.map((post) => {
                                    const studioStatus = studioPostStatus(
                                        post.status,
                                    );

                                    return (
                                        <tr
                                            key={post.id}
                                            onClick={() =>
                                                router.visit(show.url(post.id))
                                            }
                                        >
                                            <td className="c-num">
                                                P-{post.number}
                                            </td>
                                            <td className="c-title">
                                                {post.title}
                                            </td>
                                            <td className="c-plat">
                                                {post.platforms.map(
                                                    (platform) => {
                                                        const key =
                                                            normalizePlatformKey(
                                                                platform,
                                                            );
                                                        const meta = key
                                                            ? PLATFORM_META[key]
                                                            : null;

                                                        return meta ? (
                                                            <span
                                                                key={platform}
                                                                className="platform-badge"
                                                                style={{
                                                                    background:
                                                                        meta.color,
                                                                }}
                                                                title={
                                                                    platform
                                                                }
                                                            >
                                                                {meta.badge}
                                                            </span>
                                                        ) : (
                                                            <span
                                                                key={platform}
                                                                className="platform-badge"
                                                                style={{
                                                                    background:
                                                                        '#666',
                                                                }}
                                                                title={
                                                                    platform
                                                                }
                                                            >
                                                                ?
                                                            </span>
                                                        );
                                                    },
                                                )}
                                            </td>
                                            <td className="c-status">
                                                <span
                                                    className={`pill st-${studioStatus}`}
                                                >
                                                    {
                                                        POST_STATUS_LABELS[
                                                            studioStatus
                                                        ]
                                                    }
                                                </span>
                                            </td>
                                            <td className="c-act">
                                                <Link
                                                    href={show.url(post.id)}
                                                    className="viewbtn"
                                                    onClick={(event) =>
                                                        event.stopPropagation()
                                                    }
                                                >
                                                    View
                                                </Link>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {posts.links.length > 3 && (
                    <nav className="mt-4 flex flex-wrap items-center gap-1">
                        {posts.links.map((link, position) =>
                            link.url ? (
                                <Link
                                    key={`${link.label}-${position}`}
                                    href={link.url}
                                    preserveScroll
                                    className={`rounded-md border px-3 py-1 text-sm ${link.active ? 'bg-[var(--accent)] text-white' : ''}`}
                                >
                                    {paginationLabel(link.label)}
                                </Link>
                            ) : (
                                <span
                                    key={`${link.label}-${position}`}
                                    className="rounded-md border px-3 py-1 text-sm opacity-50"
                                >
                                    {paginationLabel(link.label)}
                                </span>
                            ),
                        )}
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
