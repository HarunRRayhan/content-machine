import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { studioPostStatus } from '@/lib/platform-meta';

const POST_PIPELINE = [
    { key: 'draft', label: 'Draft' },
    { key: 'scheduled', label: 'Scheduled' },
    { key: 'posted', label: 'Posted' },
] as const;

const DHAKA_TZ = 'Asia/Dhaka';

export type PostsyncerGroup = {
    post_id?: string;
    status?: string;
    scheduled_at?: string | null;
    published_at?: string | null;
    platforms?: string[];
    language?: string;
};

type Props = {
    postId: number;
    title: string;
    status: string;
    platforms: string[];
    imageDriveUrls: string[];
    publishUrl: string;
    postsyncerReady: boolean;
    publishState: string;
    needsConfirmAsk: boolean;
    postsyncer: Record<string, unknown> | null;
};

function mapStudioStatus(status: string): string {
    const mapped = studioPostStatus(status);

    if (mapped === 'archived') {
        return 'archived';
    }

    return mapped;
}

function publishGroups(
    postsyncer: Record<string, unknown> | null,
): PostsyncerGroup[] {
    const groups = postsyncer?.groups;

    if (!Array.isArray(groups)) {
        return [];
    }

    return groups.filter(
        (group): group is PostsyncerGroup =>
            group !== null && typeof group === 'object',
    );
}

function groupWhen(group: PostsyncerGroup): string | null {
    return group.published_at ?? group.scheduled_at ?? null;
}

function formatWhen(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: DHAKA_TZ,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function earliestWhen(groups: PostsyncerGroup[]): string | null {
    let best: Date | null = null;
    let bestRaw: string | null = null;

    for (const group of groups) {
        const raw = groupWhen(group);

        if (!raw) {
            continue;
        }

        const date = new Date(raw);

        if (Number.isNaN(date.getTime())) {
            continue;
        }

        if (!best || date < best) {
            best = date;
            bestRaw = raw;
        }
    }

    return formatWhen(bestRaw);
}

function datetimeLocalNowInDhaka(date = new Date()): string {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: DHAKA_TZ,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);

    const get = (type: Intl.DateTimeFormatPartTypes): string =>
        parts.find((part) => part.type === type)?.value ?? '';

    return `${get('year')}-${get('month')}-${get('day')}T${get('hour')}:${get('minute')}`;
}

export default function PostOverview({
    postId,
    title,
    status,
    platforms,
    imageDriveUrls,
    publishUrl,
    postsyncerReady,
    publishState,
    needsConfirmAsk,
    postsyncer,
}: Props) {
    const studioStatus = mapStudioStatus(status);
    const archived = studioStatus === 'archived';
    const stage = archived
        ? POST_PIPELINE.length - 1
        : Math.max(
              0,
              POST_PIPELINE.findIndex((step) => step.key === studioStatus),
          );
    const [busy, setBusy] = useState(false);
    const [confirmAskChecked, setConfirmAskChecked] = useState(false);
    const [minWhen] = useState(() => datetimeLocalNowInDhaka());
    const groups = publishGroups(postsyncer);
    const hasGroups = groups.length > 0;
    const fakeScheduled = studioStatus === 'scheduled' && !hasGroups;
    const publishBusy = ['queued', 'running'].includes(publishState);
    const showScheduleForm =
        !archived &&
        studioStatus !== 'posted' &&
        (studioStatus === 'draft' || fakeScheduled);
    const canSchedule =
        postsyncerReady &&
        !publishBusy &&
        (studioStatus === 'draft' || fakeScheduled);
    const scheduleDisabled =
        !canSchedule || (needsConfirmAsk && !confirmAskChecked);
    const scheduledAt = earliestWhen(groups);

    function advanceStatus(nextStatus: 'archived' | 'posted') {
        if (nextStatus === 'posted' && studioStatus !== 'archived') {
            return;
        }

        setBusy(true);
        router.patch(
            `/posts/${postId}`,
            { title, status: nextStatus },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    }

    return (
        <div className="overview">
            <section className="pane">
                <div className="pane-head">
                    <span className="k">Status</span>
                </div>
                {platforms.length > 0 && (
                    <div className="doc-chips">
                        <span className="chip">
                            📡 <b>{platforms.join(', ')}</b>
                        </span>
                    </div>
                )}
                <div className="statusbar">
                    <div className="stepper">
                        {POST_PIPELINE.flatMap((step, index) => {
                            const cls = archived
                                ? 'done'
                                : index < stage
                                  ? 'done'
                                  : index === stage
                                    ? 'cur'
                                    : 'todo';
                            const mark =
                                archived || index < stage ? '✓' : index + 1;
                            const node = (
                                <div key={step.key} className={`step ${cls}`}>
                                    <span className="dot">{mark}</span>
                                    <span className="slabel">{step.label}</span>
                                </div>
                            );

                            if (index === POST_PIPELINE.length - 1) {
                                return [node];
                            }

                            return [
                                node,
                                <span
                                    key={`${step.key}-conn`}
                                    className="conn"
                                />,
                            ];
                        })}
                    </div>

                    <div className="act">
                        {archived ? (
                            <>
                                <span className="badge archived">
                                    🗄️ Archived
                                </span>
                                <button
                                    type="button"
                                    className="undo"
                                    disabled={busy}
                                    onClick={() => advanceStatus('posted')}
                                >
                                    ↩︎ Unarchive
                                </button>
                            </>
                        ) : studioStatus === 'posted' ? (
                            <>
                                <span className="badge">
                                    📮 Posted · done ✓
                                </span>
                                <button
                                    type="button"
                                    className="advance"
                                    disabled={busy}
                                    onClick={() => advanceStatus('archived')}
                                >
                                    🗄️ Archive
                                </button>
                            </>
                        ) : showScheduleForm ? (
                            <Form
                                action={publishUrl}
                                method="post"
                                className="schedule-it"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <label className="schedule-it-label">
                                            <span className="schedule-it-label-row">
                                                Schedule it
                                                <span className="schedule-it-tz">
                                                    {DHAKA_TZ}
                                                </span>
                                            </span>
                                            <input
                                                name="when"
                                                type="datetime-local"
                                                required
                                                min={minWhen}
                                                disabled={!canSchedule}
                                            />
                                        </label>
                                        {needsConfirmAsk && (
                                            <label className="schedule-it-confirm">
                                                <input
                                                    type="checkbox"
                                                    name="confirm_ask"
                                                    value="1"
                                                    checked={confirmAskChecked}
                                                    onChange={(event) =>
                                                        setConfirmAskChecked(
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                                Confirm ask-gated platforms
                                            </label>
                                        )}
                                        <button
                                            type="submit"
                                            className="advance"
                                            disabled={
                                                processing || scheduleDisabled
                                            }
                                        >
                                            🗓️ Schedule it
                                        </button>
                                        {errors.when && (
                                            <p className="schedule-it-error">
                                                {errors.when}
                                            </p>
                                        )}
                                        {errors.publish && (
                                            <p className="schedule-it-error">
                                                {errors.publish}
                                            </p>
                                        )}
                                        {!postsyncerReady && (
                                            <p className="schedule-it-hint">
                                                Configure PostSyncer in Settings
                                                before scheduling.
                                            </p>
                                        )}
                                        {publishBusy && (
                                            <p className="schedule-it-hint">
                                                A publish job is already queued
                                                or running.
                                            </p>
                                        )}
                                    </>
                                )}
                            </Form>
                        ) : (
                            <span className="badge">
                                🗓️ Scheduled
                                {scheduledAt
                                    ? ` · ${scheduledAt} ${DHAKA_TZ}`
                                    : ''}
                            </span>
                        )}
                    </div>

                    {hasGroups ? (
                        <div className="schedule-log">
                            <div className="schedule-log-h">
                                Scheduled posts
                            </div>
                            <ul>
                                {groups.map((group, index) => {
                                    const when = formatWhen(groupWhen(group));

                                    return (
                                        <li
                                            key={`${group.post_id ?? 'group'}-${index}`}
                                        >
                                            <span className="schedule-log-when">
                                                {when
                                                    ? `${when} ${DHAKA_TZ}`
                                                    : 'No time yet'}
                                            </span>
                                            {group.platforms &&
                                            group.platforms.length > 0
                                                ? ` · ${group.platforms.join(', ')}`
                                                : ''}
                                            {group.post_id
                                                ? ` · PostSyncer #${group.post_id}`
                                                : ''}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    ) : fakeScheduled ? (
                        <div className="schedule-log">
                            <div className="schedule-log-h">
                                Scheduled posts
                            </div>
                            <p className="schedule-log-empty">
                                No PostSyncer schedule yet
                            </p>
                        </div>
                    ) : null}
                </div>
            </section>

            <section className="pane">
                <div className="pane-head">
                    <span className="k">Drive URLs</span>
                </div>
                <Form
                    action={`/posts/${postId}`}
                    method="patch"
                    className="drive-urls"
                    options={{ preserveScroll: true }}
                >
                    {({ processing, errors }) => (
                        <>
                            <input type="hidden" name="title" value={title} />
                            <label>
                                Image Drive URLs
                                <textarea
                                    name="image_drive_urls"
                                    defaultValue={imageDriveUrls.join('\n')}
                                    rows={4}
                                    placeholder="One Google Drive URL per line"
                                />
                            </label>
                            <p className="drive-urls-hint">
                                One Google Drive URL per line. Used when this
                                post has no uploaded attachments.
                            </p>
                            <button
                                type="submit"
                                className="advance"
                                disabled={processing}
                            >
                                Save
                            </button>
                            {errors.image_drive_urls && (
                                <p className="drive-urls-error">
                                    {errors.image_drive_urls}
                                </p>
                            )}
                            {errors.title && (
                                <p className="drive-urls-error">
                                    {errors.title}
                                </p>
                            )}
                        </>
                    )}
                </Form>
            </section>
        </div>
    );
}
