import { Head, Link } from '@inertiajs/react';
import { index as calendarIndex } from '@/routes/calendar';
import { home } from '@/routes/dashboard';
import { index as postsIndex } from '@/routes/posts';
import { index as scratchpadIndex } from '@/routes/scratchpad';
import { index as videosIndex } from '@/routes/videos';

type UpcomingItem = {
    kind: 'post' | 'video';
    id: number;
    human_id: string;
    title: string;
    state: 'scheduled' | 'published';
    at: string;
    date: string;
    href: string;
};

type PageProps = {
    scratchpad: {
        untriaged: number;
        total: number;
    };
    videos: {
        ideation: number;
        pending: number;
        ready: number;
        recorded: number;
        scheduled: number;
        posted: number;
        total: number;
    };
    posts: {
        ideation: number;
        draft: number;
        scheduled: number;
        posted: number;
    };
    upcoming: UpcomingItem[];
    timezone: string;
};

function timeLabel(at: string, timezone: string): string {
    const date = new Date(at);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

export default function DashboardHome({
    scratchpad,
    videos,
    posts,
    upcoming,
    timezone,
}: PageProps) {
    return (
        <>
            <Head title="Dashboard" />

            <div className="studio-page studio-home flex h-full flex-1 flex-col">
                <div className="home-head">
                    <h2 className="home-h">Dashboard</h2>
                </div>
                <p className="dash-lead">
                    What is in the pipeline, and what is coming up.
                </p>

                <div className="dash-grid">
                    <section className="dash-card">
                        <Link href={scratchpadIndex()} className="dash-card-h">
                            Scratch Pad
                        </Link>
                        <div className="stats">
                            <Link href={scratchpadIndex()} className="stat">
                                <div className="n">{scratchpad.untriaged}</div>
                                <div className="k">Untriaged</div>
                            </Link>
                            <div className="stat">
                                <div className="n">{scratchpad.total}</div>
                                <div className="k">Total</div>
                            </div>
                        </div>
                    </section>

                    <section className="dash-card">
                        <Link href={videosIndex()} className="dash-card-h">
                            Videos
                        </Link>
                        <div className="stats">
                            <Link href={videosIndex()} className="stat">
                                <div className="n">{videos.total}</div>
                                <div className="k">Total</div>
                            </Link>
                            <Link
                                href={videosIndex.url({
                                    query: { status: 'posted' },
                                })}
                                className="stat s-posted"
                            >
                                <div className="n">{videos.posted}</div>
                                <div className="k">Published</div>
                            </Link>
                            <Link
                                href={videosIndex.url({
                                    query: { status: 'recorded' },
                                })}
                                className="stat s-recorded"
                            >
                                <div className="n">{videos.recorded}</div>
                                <div className="k">Recorded</div>
                            </Link>
                            <Link
                                href={videosIndex.url({
                                    query: { status: 'scheduled' },
                                })}
                                className="stat s-scheduled"
                            >
                                <div className="n">{videos.scheduled}</div>
                                <div className="k">Scheduled</div>
                            </Link>
                        </div>
                    </section>

                    <section className="dash-card">
                        <Link href={postsIndex()} className="dash-card-h">
                            Posts
                        </Link>
                        <div className="stats">
                            <Link
                                href={postsIndex.url({
                                    query: { status: 'draft' },
                                })}
                                className="stat"
                            >
                                <div className="n">{posts.draft}</div>
                                <div className="k">Draft</div>
                            </Link>
                            <Link
                                href={postsIndex.url({
                                    query: { status: 'scheduled' },
                                })}
                                className="stat s-scheduled"
                            >
                                <div className="n">{posts.scheduled}</div>
                                <div className="k">Scheduled</div>
                            </Link>
                            <Link
                                href={postsIndex.url({
                                    query: { status: 'posted' },
                                })}
                                className="stat s-posted"
                            >
                                <div className="n">{posts.posted}</div>
                                <div className="k">Posted</div>
                            </Link>
                            <Link
                                href={postsIndex.url({
                                    query: { status: 'ideation' },
                                })}
                                className="stat"
                            >
                                <div className="n">{posts.ideation}</div>
                                <div className="k">Ideas</div>
                            </Link>
                        </div>
                    </section>
                </div>

                <section className="dash-upcoming">
                    <div className="dash-upcoming-h">
                        <h3>Coming up</h3>
                        <Link href={calendarIndex()}>Open calendar</Link>
                    </div>
                    {upcoming.length === 0 ? (
                        <p className="dash-upcoming-empty">
                            Nothing scheduled in the next two weeks.
                        </p>
                    ) : (
                        <ul>
                            {upcoming.map((item) => (
                                <li key={`${item.kind}-${item.id}-${item.at}`}>
                                    <Link href={item.href}>
                                        <span className="dash-upcoming-id">
                                            {item.human_id}
                                        </span>
                                        <span className="dash-upcoming-title">
                                            {item.title}
                                        </span>
                                        <span className="dash-upcoming-when">
                                            {timeLabel(item.at, timezone)}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>
            </div>
        </>
    );
}

DashboardHome.layout = {
    breadcrumbs: [{ title: 'Dashboard', href: home() }],
};
