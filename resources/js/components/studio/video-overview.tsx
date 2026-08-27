import { Form, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import ImageGallery from '@/components/studio/image-gallery';

type TalkingPoint = {
    label: string;
    text: string;
};

export type PostsyncerGroup = {
    post_id?: string;
    status?: string;
    scheduled_at?: string | null;
    published_at?: string | null;
    platforms?: string[];
    language?: string;
};

const PIPELINE = [
    { key: 'pending', label: 'Pending', prompt: null },
    { key: 'ready', label: 'Ready', prompt: '✅ Ready?' },
    { key: 'recorded', label: 'Recorded', prompt: '🎥 Recorded?' },
    { key: 'scheduled', label: 'Scheduled', prompt: null },
    { key: 'posted', label: 'Published', prompt: null },
] as const;

const DHAKA_TZ = 'Asia/Dhaka';

type DriveCheck = {
    status: 'idle' | 'checking' | 'ok' | 'bad';
    message: string;
};

const IDLE_CHECK: DriveCheck = { status: 'idle', message: '' };

function csrfToken(): string {
    return (
        document
            .querySelector('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function checkDriveUrl(url: string): Promise<DriveCheck> {
    const trimmed = url.trim();

    if (trimmed === '') {
        return IDLE_CHECK;
    }

    const response = await fetch('/media-urls/check', {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrfToken(),
        },
        body: JSON.stringify({ url: trimmed }),
    });

    const payload = (await response.json()) as {
        accessible?: boolean;
        message?: string;
        errors?: { url?: string[] };
    };

    if (!response.ok) {
        return {
            status: 'bad',
            message:
                payload.errors?.url?.[0] ??
                payload.message ??
                'Could not check this link.',
        };
    }

    return {
        status: payload.accessible ? 'ok' : 'bad',
        message:
            payload.message ??
            (payload.accessible
                ? 'Anyone with the link can fetch this file.'
                : 'This Google Drive file is not public.'),
    };
}

const MANUAL_STATUSES = new Set(['pending', 'ready', 'recorded', 'archived']);

type Props = {
    videoId: number;
    title: string;
    status: string;
    lang: string;
    length: string;
    points: TalkingPoint[];
    storageKey: string;
    images: Array<{
        filename: string;
        url: string;
        mime: string;
    }>;
    videoDriveUrl: string | null;
    coverDriveUrl: string | null;
    publishUrl: string;
    postsyncerReady: boolean;
    publishState: string;
    needsConfirmAsk: boolean;
    postsyncer: Record<string, unknown> | null;
};

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

function checksKey(storageKey: string): string {
    return `cm:points:${storageKey}`;
}

function readChecks(storageKey: string): Set<number> {
    if (typeof window === 'undefined') {
        return new Set();
    }

    try {
        const raw = window.localStorage.getItem(checksKey(storageKey));
        const parsed = raw ? (JSON.parse(raw) as number[]) : [];

        return new Set(parsed);
    } catch {
        return new Set();
    }
}

function writeChecks(storageKey: string, checks: Set<number>): void {
    window.localStorage.setItem(
        checksKey(storageKey),
        JSON.stringify([...checks]),
    );
}

export default function VideoOverview({
    videoId,
    title,
    status,
    lang,
    length,
    points,
    storageKey,
    images,
    videoDriveUrl,
    coverDriveUrl,
    publishUrl,
    postsyncerReady,
    publishState,
    needsConfirmAsk,
    postsyncer,
}: Props) {
    const [checks, setChecks] = useState<Set<number>>(() =>
        readChecks(storageKey),
    );
    const [busy, setBusy] = useState(false);
    const [confirmAskChecked, setConfirmAskChecked] = useState(false);
    const [minWhen] = useState(() => datetimeLocalNowInDhaka());
    const [videoCheck, setVideoCheck] = useState<DriveCheck>(IDLE_CHECK);
    const [coverCheck, setCoverCheck] = useState<DriveCheck>(IDLE_CHECK);

    const studioStatus = useMemo(() => {
        if (status === 'draft' || status === 'dropped') {
            return 'pending';
        }

        if (status === 'archived') {
            return 'archived';
        }

        return status;
    }, [status]);

    const archived = studioStatus === 'archived';
    const stage = archived
        ? PIPELINE.length - 1
        : Math.max(
              0,
              PIPELINE.findIndex((step) => step.key === studioStatus),
          );

    const doneCount = checks.size;
    const total = points.length;
    const pct = total > 0 ? Math.round((doneCount / total) * 100) : 0;

    function togglePoint(index: number) {
        const next = new Set(checks);

        if (next.has(index)) {
            next.delete(index);
        } else {
            next.add(index);
        }

        setChecks(next);
        writeChecks(storageKey, next);
    }

    function resetPoints() {
        const next = new Set<number>();
        setChecks(next);
        writeChecks(storageKey, next);
    }

    function advanceStatus(nextStatus: string) {
        const allowed =
            MANUAL_STATUSES.has(nextStatus) ||
            (nextStatus === 'posted' && studioStatus === 'archived');

        if (!allowed) {
            return;
        }

        setBusy(true);
        router.patch(
            `/videos/${videoId}`,
            {
                title,
                status: nextStatus,
                video_drive_url: videoDriveUrl ?? '',
                cover_drive_url: coverDriveUrl ?? '',
            },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    }

    const groups = publishGroups(postsyncer);
    const hasGroups = groups.length > 0;
    const fakeScheduled = studioStatus === 'scheduled' && !hasGroups;
    const publishBusy = ['queued', 'running'].includes(publishState);
    const showScheduleForm =
        !archived &&
        studioStatus !== 'posted' &&
        (studioStatus === 'recorded' || fakeScheduled);
    const hasVideoDriveUrl = Boolean(videoDriveUrl?.trim());
    const missingVideoDriveUrl =
        !hasVideoDriveUrl && studioStatus === 'recorded';
    const canSchedule =
        postsyncerReady &&
        !publishBusy &&
        hasVideoDriveUrl &&
        (studioStatus === 'recorded' || fakeScheduled);
    const scheduleDisabled =
        !canSchedule || (needsConfirmAsk && !confirmAskChecked);
    const scheduledAt = earliestWhen(groups);

    const chips = [
        lang ? (
            <span key="lang" className="chip">
                🗣 <b>{lang}</b>
            </span>
        ) : null,
        length ? (
            <span key="length" className="chip">
                ⏱ <b>{length}</b>
            </span>
        ) : null,
    ].filter(Boolean);

    return (
        <div className="overview">
            <section className="pane">
                <div className="pane-head">
                    <span className="k">Status</span>
                </div>
                <div className="doc-chips">{chips}</div>
                <div className="statusbar">
                    <div className="stepper">
                        {PIPELINE.flatMap((step, index) => {
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

                            if (index === PIPELINE.length - 1) {
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

                    <Form
                        action={`/videos/${videoId}`}
                        method="patch"
                        className="drive-urls"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <>
                                <input
                                    type="hidden"
                                    name="title"
                                    value={title}
                                />
                                <div className="drive-urls-h">Drive URLs</div>
                                <p className="drive-urls-hint">
                                    Paste a live Google Drive file link. Share
                                    it as Anyone with the link so PostSyncer can
                                    fetch it.
                                </p>
                                <div className="drive-urls-row">
                                    <label className="schedule-it-label">
                                        Video Drive URL
                                        <input
                                            name="video_drive_url"
                                            type="url"
                                            defaultValue={videoDriveUrl ?? ''}
                                            placeholder="https://drive.google.com/file/d/..."
                                            maxLength={2048}
                                            onBlur={(event) => {
                                                const value =
                                                    event.target.value.trim();

                                                if (value === '') {
                                                    setVideoCheck(IDLE_CHECK);

                                                    return;
                                                }

                                                setVideoCheck({
                                                    status: 'checking',
                                                    message: 'Checking…',
                                                });
                                                void checkDriveUrl(value).then(
                                                    setVideoCheck,
                                                );
                                            }}
                                        />
                                        {videoCheck.status !== 'idle' && (
                                            <span
                                                className={`drive-url-status is-${videoCheck.status}`}
                                            >
                                                {videoCheck.message}
                                            </span>
                                        )}
                                    </label>
                                    <label className="schedule-it-label">
                                        Cover Drive URL
                                        <input
                                            name="cover_drive_url"
                                            type="url"
                                            defaultValue={coverDriveUrl ?? ''}
                                            placeholder="https://drive.google.com/file/d/..."
                                            maxLength={2048}
                                            onBlur={(event) => {
                                                const value =
                                                    event.target.value.trim();

                                                if (value === '') {
                                                    setCoverCheck(IDLE_CHECK);

                                                    return;
                                                }

                                                setCoverCheck({
                                                    status: 'checking',
                                                    message: 'Checking…',
                                                });
                                                void checkDriveUrl(value).then(
                                                    setCoverCheck,
                                                );
                                            }}
                                        />
                                        {coverCheck.status !== 'idle' && (
                                            <span
                                                className={`drive-url-status is-${coverCheck.status}`}
                                            >
                                                {coverCheck.message}
                                            </span>
                                        )}
                                    </label>
                                    <button
                                        type="submit"
                                        className="advance"
                                        disabled={processing || busy}
                                    >
                                        Save
                                    </button>
                                </div>
                                {errors.video_drive_url && (
                                    <p className="schedule-it-error">
                                        {errors.video_drive_url}
                                    </p>
                                )}
                                {errors.cover_drive_url && (
                                    <p className="schedule-it-error">
                                        {errors.cover_drive_url}
                                    </p>
                                )}
                                {errors.title && (
                                    <p className="schedule-it-error">
                                        {errors.title}
                                    </p>
                                )}
                                {missingVideoDriveUrl && (
                                    <p className="schedule-it-hint">
                                        Cannot schedule a reel without a video
                                        Drive URL.
                                    </p>
                                )}
                            </>
                        )}
                    </Form>

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
                                    📮 Published · done ✓
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
                            <>
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
                                                        checked={
                                                            confirmAskChecked
                                                        }
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
                                                    processing ||
                                                    scheduleDisabled
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
                                                    Configure PostSyncer in
                                                    Settings before scheduling.
                                                </p>
                                            )}
                                            {missingVideoDriveUrl && (
                                                <p className="schedule-it-hint">
                                                    Cannot schedule a reel
                                                    without a video Drive URL.
                                                </p>
                                            )}
                                            {publishBusy && (
                                                <p className="schedule-it-hint">
                                                    A publish job is already
                                                    queued or running.
                                                </p>
                                            )}
                                        </>
                                    )}
                                </Form>
                                {studioStatus === 'recorded' && stage > 0 && (
                                    <button
                                        type="button"
                                        className="undo"
                                        disabled={busy}
                                        onClick={() =>
                                            advanceStatus(
                                                PIPELINE[stage - 1].key,
                                            )
                                        }
                                    >
                                        ↩︎ Undo
                                    </button>
                                )}
                            </>
                        ) : studioStatus === 'scheduled' ? (
                            <span className="badge">
                                🗓️ Scheduled
                                {scheduledAt
                                    ? ` · ${scheduledAt} ${DHAKA_TZ}`
                                    : ''}
                            </span>
                        ) : (
                            <>
                                <button
                                    type="button"
                                    className="advance"
                                    disabled={busy}
                                    onClick={() =>
                                        advanceStatus(PIPELINE[stage + 1].key)
                                    }
                                >
                                    {PIPELINE[stage + 1].prompt}
                                </button>
                                {stage > 0 && (
                                    <button
                                        type="button"
                                        className="undo"
                                        disabled={busy}
                                        onClick={() =>
                                            advanceStatus(
                                                PIPELINE[stage - 1].key,
                                            )
                                        }
                                    >
                                        ↩︎ Undo
                                    </button>
                                )}
                            </>
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

            <ImageGallery images={images} />

            <section className="pane">
                <div className="side-head">
                    <div
                        className="ring"
                        style={{ ['--pct' as string]: String(pct) }}
                    >
                        <i>
                            {doneCount}/{total}
                        </i>
                    </div>
                    <div>
                        <div className="lbl">🎯 Talking points</div>
                        <div className="cnt">
                            <b>{doneCount}</b> / {total} covered
                        </div>
                    </div>
                    <button
                        type="button"
                        className="reset"
                        onClick={resetPoints}
                    >
                        ↺ Reset
                    </button>
                </div>

                <div
                    className={`alldone${total > 0 && doneCount === total ? 'show' : ''}`}
                >
                    ✓ All points covered
                </div>

                <ul className="points">
                    {points.map((point, index) => (
                        <li
                            key={`${index}-${point.text.slice(0, 24)}`}
                            className="pt"
                            data-done={checks.has(index)}
                            onClick={() => togglePoint(index)}
                        >
                            <span className="box">
                                {checks.has(index) ? '✓' : ''}
                            </span>
                            <span className="txt">
                                <span className="num">{index + 1}.</span>
                                {point.label ? (
                                    <>
                                        <span className="plabel">
                                            {point.label}
                                        </span>
                                        : {point.text}
                                    </>
                                ) : (
                                    point.text
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            </section>
        </div>
    );
}
