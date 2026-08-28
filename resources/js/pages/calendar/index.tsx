import { Head, Link, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { index } from '@/routes/calendar';

type CalendarEvent = {
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
    year: number;
    month: number;
    timezone: string;
    today: string;
    events: CalendarEvent[];
};

type KindFilter = 'all' | 'post' | 'video';
type StateFilter = 'all' | 'scheduled' | 'published';

const WEEKDAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function pad(value: number): string {
    return String(value).padStart(2, '0');
}

function dateKey(year: number, month: number, day: number): string {
    return `${year}-${pad(month)}-${pad(day)}`;
}

function shiftMonth(
    year: number,
    month: number,
    delta: number,
): {
    year: number;
    month: number;
} {
    const next = new Date(Date.UTC(year, month - 1 + delta, 1));

    return { year: next.getUTCFullYear(), month: next.getUTCMonth() + 1 };
}

function monthLabel(year: number, month: number): string {
    return new Intl.DateTimeFormat('en-GB', {
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    }).format(new Date(Date.UTC(year, month - 1, 1)));
}

function timeLabel(at: string, timezone: string): string {
    const date = new Date(at);

    if (Number.isNaN(date.getTime())) {
        return '';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}

function buildCells(
    year: number,
    month: number,
): Array<{
    key: string;
    day: number;
    inMonth: boolean;
}> {
    const first = new Date(Date.UTC(year, month - 1, 1));
    const startPad = first.getUTCDay();
    const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
    const prevMonth = shiftMonth(year, month, -1);
    const daysInPrev = new Date(
        Date.UTC(prevMonth.year, prevMonth.month, 0),
    ).getUTCDate();
    const nextMonth = shiftMonth(year, month, 1);

    const cells: Array<{ key: string; day: number; inMonth: boolean }> = [];

    for (let i = startPad - 1; i >= 0; i--) {
        const day = daysInPrev - i;
        cells.push({
            key: dateKey(prevMonth.year, prevMonth.month, day),
            day,
            inMonth: false,
        });
    }

    for (let day = 1; day <= daysInMonth; day++) {
        cells.push({
            key: dateKey(year, month, day),
            day,
            inMonth: true,
        });
    }

    let nextDay = 1;

    while (cells.length % 7 !== 0 || cells.length < 42) {
        cells.push({
            key: dateKey(nextMonth.year, nextMonth.month, nextDay),
            day: nextDay,
            inMonth: false,
        });
        nextDay += 1;

        if (cells.length >= 42) {
            break;
        }
    }

    return cells;
}

export default function CalendarIndex({
    year,
    month,
    timezone,
    today,
    events,
}: PageProps) {
    const [kind, setKind] = useState<KindFilter>('all');
    const [state, setState] = useState<StateFilter>('all');

    const filtered = useMemo(
        () =>
            events.filter((event) => {
                if (kind !== 'all' && event.kind !== kind) {
                    return false;
                }

                return state === 'all' || event.state === state;
            }),
        [events, kind, state],
    );

    const byDate = useMemo(() => {
        const map = new Map<string, CalendarEvent[]>();

        for (const event of filtered) {
            const list = map.get(event.date) ?? [];
            list.push(event);
            map.set(event.date, list);
        }

        return map;
    }, [filtered]);

    const cells = useMemo(() => buildCells(year, month), [year, month]);
    const prev = shiftMonth(year, month, -1);
    const next = shiftMonth(year, month, 1);

    return (
        <>
            <Head title="Calendar" />

            <div className="studio-page flex min-h-full flex-1 flex-col gap-3 p-4">
                <div className="cal-head">
                    <h2 className="home-h">Calendar</h2>
                    <p className="cal-sub">
                        Scheduled and published posts and videos · {timezone}
                    </p>
                </div>

                <div className="cal-toolbar">
                    <div className="cal-nav">
                        <Link
                            href={index.url({
                                query: { year: prev.year, month: prev.month },
                            })}
                            className="pgbtn"
                            preserveScroll
                        >
                            ‹
                        </Link>
                        <span className="cal-month">
                            {monthLabel(year, month)}
                        </span>
                        <Link
                            href={index.url({
                                query: { year: next.year, month: next.month },
                            })}
                            className="pgbtn"
                            preserveScroll
                        >
                            ›
                        </Link>
                        <button
                            type="button"
                            className="pgbtn"
                            onClick={() => {
                                const [todayYear, todayMonth] = today
                                    .split('-')
                                    .map(Number);
                                router.get(
                                    index.url({
                                        query: {
                                            year: todayYear,
                                            month: todayMonth,
                                        },
                                    }),
                                    {},
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            Today
                        </button>
                    </div>

                    <div className="tabbar statustabs" role="tablist">
                        {(
                            [
                                ['all', 'All'],
                                ['post', 'Posts'],
                                ['video', 'Videos'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                role="tab"
                                aria-selected={kind === value}
                                onClick={() => setKind(value)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>

                    <div className="tabbar statustabs" role="tablist">
                        {(
                            [
                                ['all', 'All'],
                                ['scheduled', 'Scheduled'],
                                ['published', 'Published'],
                            ] as const
                        ).map(([value, label]) => (
                            <button
                                key={value}
                                type="button"
                                role="tab"
                                aria-selected={state === value}
                                onClick={() => setState(value)}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>

                <div className="cal-grid" role="grid">
                    {WEEKDAYS.map((day) => (
                        <div key={day} className="cal-dow">
                            {day}
                        </div>
                    ))}
                    {cells.map((cell) => {
                        const dayEvents = byDate.get(cell.key) ?? [];
                        const isToday = cell.key === today;

                        return (
                            <div
                                key={cell.key}
                                role="gridcell"
                                className={[
                                    'cal-day',
                                    cell.inMonth ? '' : 'is-out',
                                    isToday ? 'is-today' : '',
                                ]
                                    .filter(Boolean)
                                    .join(' ')}
                            >
                                <span className="cal-num">{cell.day}</span>
                                <div className="cal-events">
                                    {dayEvents.map((event) => (
                                        <Link
                                            key={`${event.kind}-${event.id}-${event.at}-${event.state}`}
                                            href={event.href}
                                            className={`cal-chip is-${event.state} is-${event.kind}`}
                                            title={`${event.human_id} · ${event.title}`}
                                        >
                                            <span className="cal-chip-time">
                                                {timeLabel(event.at, timezone)}
                                            </span>
                                            <span className="cal-chip-kind">
                                                {event.kind === 'video'
                                                    ? 'Video'
                                                    : 'Post'}
                                            </span>
                                            <span className="cal-chip-id">
                                                {event.human_id}
                                            </span>
                                            <span className="cal-chip-title">
                                                {event.title}
                                            </span>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        );
                    })}
                </div>
            </div>
        </>
    );
}

CalendarIndex.layout = {
    breadcrumbs: [{ title: 'Calendar', href: index() }],
};
