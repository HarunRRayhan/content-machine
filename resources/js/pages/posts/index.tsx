import { Head, Link, router } from '@inertiajs/react';
import TemplatePreview from '@/components/media/template-preview';
import { IndexWorkspaceChips } from '@/components/studio/workspace-schedule';
import type {
    PostsyncerGroup,
    WorkspaceBucket,
} from '@/components/studio/workspace-schedule';
import { postShowUrl } from '@/lib/content-urls';
import { POST_STATUS_LABELS, studioPostStatus } from '@/lib/platform-meta';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { show as showTemplate } from '@/routes/media/templates';
import { index } from '@/routes/posts';

type IdeaRow = {
    type: 'idea';
    id: number;
    human_id: string;
    title: string;
    score: number | null;
    trend: string | null;
};

type PostRow = {
    type: 'post';
    id: number;
    human_id: string;
    number: number;
    title: string;
    template: string | null;
    template_meta: {
        letter: string;
        name: string;
        label: string;
        preview_url: string;
        visual_identity: string;
    } | null;
    status: string;
    publish_state: string;
    language: string | null;
    platforms: string[];
    groups?: PostsyncerGroup[];
    workspaces?: WorkspaceBucket[];
    has_captions: boolean;
    has_body: boolean;
    created_at: string | null;
};

type IndexRow = IdeaRow | PostRow;

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
    ready: 'Ready',
    scheduled: 'Scheduled',
    posted: 'Posted',
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

export default function PostsIndex({
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
            <Head title="Posts" />

            <div className="studio-page flex min-h-full flex-1 flex-col gap-2 p-4">
                <h2 className="home-h">All posts</h2>

                <div className="tabbar statustabs" role="tablist">
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
                                className={active ? undefined : 'opacity-90'}
                            >
                                {TAB_LABELS[tab] ?? tab}
                                <span className="tabn">{counts[tab] ?? 0}</span>
                            </Link>
                        );
                    })}
                </div>

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
                    {!isIdeation && (
                        <select
                            value={filters.language ?? ''}
                            onChange={(event) =>
                                applyFilter({
                                    language: event.target.value || null,
                                })
                            }
                            className="rounded-md border border-[var(--line)] bg-[var(--bg2)] px-3 py-2 text-sm"
                        >
                            <option value="">All languages</option>
                            <option value="bn">Bangla</option>
                            <option value="en">English</option>
                        </select>
                    )}
                </div>

                {items.data.length === 0 ? (
                    <p className="empty">
                        No {isIdeation ? 'ideas' : 'posts'} in this tab yet.
                    </p>
                ) : (
                    <div className="vtable-wrap">
                        <table className="vtable">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Title</th>
                                    {!isIdeation && <th>Template</th>}
                                    <th>
                                        {isIdeation ? 'Score' : 'Workspaces'}
                                    </th>
                                    <th>{isIdeation ? 'Trend' : 'Status'}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {items.data.map((row) => {
                                    if (row.type === 'idea') {
                                        return (
                                            <tr
                                                key={`idea-${row.id}`}
                                                onClick={() =>
                                                    router.visit(
                                                        showIdea.url(row.id),
                                                    )
                                                }
                                            >
                                                <td className="c-num">
                                                    {row.human_id}
                                                </td>
                                                <td className="c-title">
                                                    {row.title}
                                                </td>
                                                <td>
                                                    {row.score !== null
                                                        ? `${row.score}/1000`
                                                        : '—'}
                                                </td>
                                                <td>{row.trend ?? '—'}</td>
                                                <td className="c-act">
                                                    <Link
                                                        href={showIdea.url(
                                                            row.id,
                                                        )}
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
                                    }

                                    const studioStatus = studioPostStatus(
                                        row.status,
                                    );

                                    return (
                                        <tr
                                            key={`post-${row.id}`}
                                            onClick={() =>
                                                router.visit(
                                                    postShowUrl(row.human_id),
                                                )
                                            }
                                        >
                                            <td className="c-num">
                                                {row.human_id}
                                            </td>
                                            <td className="c-title">
                                                {row.title}
                                            </td>
                                            <td>
                                                {row.template_meta ? (
                                                    <Link
                                                        href={showTemplate.url(
                                                            row.template_meta
                                                                .letter,
                                                        )}
                                                        className="group inline-flex max-w-56 items-center gap-2"
                                                        onClick={(event) =>
                                                            event.stopPropagation()
                                                        }
                                                    >
                                                        <TemplatePreview
                                                            src={
                                                                row
                                                                    .template_meta
                                                                    .preview_url
                                                            }
                                                            alt={`${row.template_meta.label} preview`}
                                                            letter={
                                                                row
                                                                    .template_meta
                                                                    .letter
                                                            }
                                                            className="size-10 shrink-0 rounded-md"
                                                        />
                                                        <span className="min-w-0">
                                                            <span className="block truncate text-sm font-medium">
                                                                {
                                                                    row
                                                                        .template_meta
                                                                        .label
                                                                }
                                                                <span className="font-normal text-[var(--ink-soft)]">
                                                                    {' · '}
                                                                    {
                                                                        row
                                                                            .template_meta
                                                                            .name
                                                                    }
                                                                </span>
                                                            </span>
                                                            <span className="block truncate text-xs text-[var(--ink-soft)]">
                                                                Type:{' '}
                                                                {
                                                                    row
                                                                        .template_meta
                                                                        .visual_identity
                                                                }
                                                            </span>
                                                        </span>
                                                    </Link>
                                                ) : (
                                                    '—'
                                                )}
                                            </td>
                                            <td className="c-plat">
                                                <IndexWorkspaceChips
                                                    workspaces={row.workspaces}
                                                    groups={row.groups ?? []}
                                                    language={row.language}
                                                    platforms={row.platforms}
                                                />
                                            </td>
                                            <td className="c-status">
                                                <span
                                                    className={`pill st-${studioStatus}`}
                                                >
                                                    {POST_STATUS_LABELS[
                                                        studioStatus
                                                    ] ?? row.status}
                                                </span>
                                                {['queued', 'running'].includes(
                                                    row.publish_state,
                                                ) && (
                                                    <span className="pill st-scheduled ml-1">
                                                        {row.publish_state}
                                                    </span>
                                                )}
                                            </td>
                                            <td className="c-act">
                                                <Link
                                                    href={postShowUrl(
                                                        row.human_id,
                                                    )}
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

                {items.links.length > 3 && (
                    <nav className="mt-4 flex flex-wrap items-center gap-1">
                        {items.links.map((link, position) =>
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

PostsIndex.layout = ({ filters }: PageProps) => ({
    breadcrumbs:
        filters.status === 'ideation'
            ? [
                  { title: 'Posts', href: index() },
                  {
                      title: 'Ideas',
                      href: index.url({ query: { status: 'ideation' } }),
                  },
              ]
            : [
                  { title: 'Dashboard', href: home() },
                  { title: 'Posts', href: index() },
              ],
});
