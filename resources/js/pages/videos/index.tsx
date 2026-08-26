import { Head, Link, router } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { scoreBand, trendKind, trendLabel } from '@/lib/studio-meta';
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
    from: number | null;
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
    return label.replaceAll('&laquo;', '«').replaceAll('&raquo;', '»');
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

function visitRow(event: MouseEvent, href: string) {
    if (
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey ||
        (event.target as HTMLElement).closest('a, button')
    ) {
        return;
    }

    router.visit(href);
}

export default function VideosIndex({
    items,
    filters,
    counts,
    tabs,
}: PageProps) {
    const isIdeation = filters.status === 'ideation';
    const published = counts.posted ?? 0;
    const recorded = counts.recorded ?? 0;
    const scheduled = counts.scheduled ?? 0;
    const total =
        (counts.pending ?? 0) +
        (counts.ready ?? 0) +
        recorded +
        scheduled +
        published;
    const rankStart = items.from ?? 1;

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

            <div className="studio-page studio-home flex h-full flex-1 flex-col">
                <div className="home-head">
                    <h2 className="home-h">All videos</h2>
                </div>

                <div className="stats">
                    <div className="stat">
                        <div className="n">{total}</div>
                        <div className="k">Total videos</div>
                    </div>
                    <div className="stat s-posted">
                        <div className="n">{published}</div>
                        <div className="k">Published</div>
                    </div>
                    <div className="stat s-recorded">
                        <div className="n">{recorded}</div>
                        <div className="k">Recorded</div>
                    </div>
                    <div className="stat s-scheduled">
                        <div className="n">{scheduled}</div>
                        <div className="k">Scheduled</div>
                    </div>
                </div>

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
                        >
                            <option value="">All languages</option>
                            <option value="bn">Bangla</option>
                            <option value="en">English</option>
                        </select>
                    )}
                </div>

                {items.data.length === 0 ? (
                    <p className="empty">
                        {isIdeation
                            ? 'No ideas in the scratch pad yet.'
                            : items.total === 0 && !filters.q
                              ? 'No videos in this stage yet.'
                              : 'No videos match this filter.'}
                    </p>
                ) : (
                    <div className="vtable-wrap">
                        <table className="vtable">
                            <thead>
                                <tr>
                                    {isIdeation ? (
                                        <>
                                            <th>Rank</th>
                                            <th>ID</th>
                                            <th>Idea</th>
                                            <th>Trend</th>
                                            <th>Relevancy</th>
                                        </>
                                    ) : (
                                        <>
                                            <th>ID</th>
                                            <th>Title</th>
                                            <th>Status</th>
                                        </>
                                    )}
                                </tr>
                            </thead>
                            <tbody>
                                {items.data.map((row, position) => {
                                    if (row.type === 'idea') {
                                        const href = showIdea.url(row.id);
                                        const kind = trendKind(row.trend);

                                        return (
                                            <tr
                                                key={`idea-${row.id}`}
                                                onClick={(event) =>
                                                    visitRow(event, href)
                                                }
                                            >
                                                <td className="c-rank">
                                                    {rankStart + position}
                                                </td>
                                                <td className="c-num">
                                                    <Link href={href}>
                                                        {row.human_id}
                                                    </Link>
                                                </td>
                                                <td className="c-title">
                                                    <Link href={href}>
                                                        {row.title}
                                                    </Link>
                                                </td>
                                                <td
                                                    className={`c-trend${kind ? ` trend-${kind}` : ''}`}
                                                >
                                                    {trendLabel(row.trend)}
                                                </td>
                                                <td className="c-status">
                                                    <span
                                                        className={`pill score ${scoreBand(row.score)}`}
                                                    >
                                                        {row.score !== null
                                                            ? `${row.score}/1000`
                                                            : '—'}
                                                    </span>
                                                </td>
                                            </tr>
                                        );
                                    }

                                    const href = show.url(row.id);

                                    return (
                                        <tr
                                            key={`video-${row.id}`}
                                            onClick={(event) =>
                                                visitRow(event, href)
                                            }
                                        >
                                            <td className="c-num">
                                                <Link href={href}>
                                                    #{row.number}
                                                </Link>
                                            </td>
                                            <td className="c-title">
                                                <Link href={href}>
                                                    {row.title}
                                                </Link>
                                            </td>
                                            <td className="c-status">
                                                <span
                                                    className={`pill st-${row.status}`}
                                                >
                                                    {TAB_LABELS[row.status] ??
                                                        row.status}
                                                </span>
                                                {['queued', 'running'].includes(
                                                    row.publish_state,
                                                ) && (
                                                    <span className="pill st-scheduled">
                                                        {row.publish_state}
                                                    </span>
                                                )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                )}

                {items.links.length > 3 && (
                    <nav className="pager">
                        {items.links.map((link, position) =>
                            link.url && !link.active ? (
                                <Link
                                    key={`${link.label}-${position}`}
                                    href={link.url}
                                    preserveScroll
                                    className="pgbtn"
                                >
                                    {paginationLabel(link.label)}
                                </Link>
                            ) : (
                                <span
                                    key={`${link.label}-${position}`}
                                    className={[
                                        'pgbtn',
                                        link.active ? 'cur' : '',
                                        link.url === null ? 'is-disabled' : '',
                                    ]
                                        .filter(Boolean)
                                        .join(' ')}
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

VideosIndex.layout = ({ filters }: PageProps) => ({
    breadcrumbs:
        filters.status === 'ideation'
            ? [
                  { title: 'Videos', href: index() },
                  {
                      title: 'Ideas',
                      href: index.url({ query: { status: 'ideation' } }),
                  },
              ]
            : [
                  { title: 'Dashboard', href: home() },
                  { title: 'Videos', href: index() },
              ],
});
