import { Form, router } from '@inertiajs/react';
import { useState } from 'react';
import { studioPostStatus } from '@/lib/platform-meta';

const POST_PIPELINE = [
    { key: 'draft', label: 'Draft' },
    { key: 'scheduled', label: 'Scheduled' },
    { key: 'posted', label: 'Posted' },
] as const;

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

function formatWhen(value: string | null | undefined): string | null {
    if (!value) {
        return null;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: 'Asia/Dhaka',
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function languageLabel(language: string | undefined): string {
    if (language === 'bangla' || language === 'bn') {
        return 'Bangla';
    }

    if (language === 'english' || language === 'en') {
        return 'English';
    }

    return language ?? 'Post';
}

export default function PostOverview({
    postId,
    title,
    status,
    platforms,
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
    const groups = publishGroups(postsyncer);
    const publishBusy = ['queued', 'running'].includes(publishState);
    const canSchedule =
        postsyncerReady && !publishBusy && studioStatus === 'draft';
    const scheduleDisabled =
        !canSchedule || (needsConfirmAsk && !confirmAskChecked);

    function advanceStatus(nextStatus: string) {
        setBusy(true);
        router.patch(
            `/dashboard/posts/${postId}`,
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
                        ) : studioStatus === 'scheduled' ? (
                            <span className="badge">🗓️ Scheduled</span>
                        ) : (
                            <Form
                                action={publishUrl}
                                method="post"
                                className="schedule-it"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <label className="schedule-it-label">
                                            Schedule it
                                            <input
                                                name="when"
                                                type="datetime-local"
                                                required
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
                        )}
                    </div>

                    {groups.length > 0 && (
                        <div className="schedule-log">
                            <div className="schedule-log-h">
                                Scheduled posts
                            </div>
                            <ul>
                                {groups.map((group, index) => {
                                    const when =
                                        formatWhen(group.published_at) ??
                                        formatWhen(group.scheduled_at);

                                    return (
                                        <li
                                            key={`${group.post_id ?? 'group'}-${index}`}
                                        >
                                            <b>
                                                {languageLabel(group.language)}
                                            </b>
                                            {group.platforms &&
                                            group.platforms.length > 0
                                                ? ` · ${group.platforms.join(', ')}`
                                                : ''}
                                            {group.post_id
                                                ? ` · PostSyncer #${group.post_id}`
                                                : ''}
                                            {when
                                                ? ` · ${when} Asia/Dhaka`
                                                : ''}
                                            {group.status
                                                ? ` · ${group.status}`
                                                : ''}
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}
                </div>
            </section>
        </div>
    );
}
