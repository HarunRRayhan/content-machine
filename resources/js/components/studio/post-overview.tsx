import { Form, Link, router } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';
import { useState } from 'react';
import PublishDialog from '@/components/content/publish-dialog';
import TemplatePreview from '@/components/media/template-preview';
import {
    PlatformChipRow,
    WorkspacePlatformChips,
    WorkspaceScheduleLog,
    bucketsFromGroups,
} from '@/components/studio/workspace-schedule';
import type {
    PostsyncerGroup,
    WorkspaceBucket,
} from '@/components/studio/workspace-schedule';
import { studioPostStatus } from '@/lib/platform-meta';
import type { HandleDirectory } from '@/lib/studio-workspaces';
import { show as showTemplate } from '@/routes/media/templates';

const POST_PIPELINE = [
    { key: 'draft', label: 'Draft' },
    { key: 'scheduled', label: 'Scheduled' },
    { key: 'posted', label: 'Posted' },
] as const;

const DEFAULT_TIMEZONE = 'Asia/Dhaka';

type Props = {
    postId: number;
    title: string;
    status: string;
    platforms: string[];
    language?: string | null;
    templateMeta: {
        letter: string;
        name: string;
        label: string;
        preview_url: string;
        visual_identity: string;
    } | null;
    workspaces?: WorkspaceBucket[];
    publishUrl: string;
    postsyncerReady: boolean;
    publishState: string;
    publishRetryable: boolean;
    publishProgress: {
        state?: string;
        current?: {
            index?: number;
            group_key?: string;
            phase?: string;
        } | null;
    } | null;
    reconcileUrl: string;
    approvalState: string;
    timezone: string;
    needsConfirmAsk: boolean;
    postsyncer: Record<string, unknown> | null;
    handles?: HandleDirectory;
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

function timezoneParts(date: Date, timezone: string): Record<string, number> {
    const parts = new Intl.DateTimeFormat('en-CA', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hourCycle: 'h23',
    }).formatToParts(date);
    const values: Record<string, number> = {};

    for (const part of parts) {
        if (part.type !== 'literal') {
            values[part.type] = Number(part.value);
        }
    }

    return values;
}

function parseNaiveWhen(value: string, timezone: string): Date | null {
    const match = value.match(
        /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2}))?$/,
    );

    if (!match) {
        return null;
    }

    const [, year, month, day, hour, minute, second = '0'] = match;
    const desired = Date.UTC(
        Number(year),
        Number(month) - 1,
        Number(day),
        Number(hour),
        Number(minute),
        Number(second),
    );
    let timestamp = desired;

    // Find the UTC instant whose wall-clock parts match the workspace zone.
    // Iteration also handles daylight-saving offset changes without shipping a
    // timezone database to the browser.
    for (let attempt = 0; attempt < 3; attempt += 1) {
        const local = timezoneParts(new Date(timestamp), timezone);
        const rendered = Date.UTC(
            local.year,
            local.month - 1,
            local.day,
            local.hour,
            local.minute,
            local.second,
        );
        timestamp += desired - rendered;
    }

    const date = new Date(timestamp);

    return Number.isNaN(date.getTime()) ? null : date;
}

function parseWhen(value: string, timezone: string): Date | null {
    const naive = parseNaiveWhen(value, timezone);

    if (naive !== null) {
        return naive;
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime()) ? null : date;
}

function formatWhen(
    value: string | null | undefined,
    timezone: string,
): string | null {
    if (!value) {
        return null;
    }

    const date = parseWhen(value, timezone);

    if (date === null) {
        return value;
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

function earliestWhen(
    groups: PostsyncerGroup[],
    timezone: string,
): string | null {
    let best: Date | null = null;
    let bestRaw: string | null = null;

    for (const group of groups) {
        const raw = groupWhen(group);

        if (!raw) {
            continue;
        }

        const date = parseWhen(raw, timezone);

        if (date === null) {
            continue;
        }

        if (!best || date < best) {
            best = date;
            bestRaw = raw;
        }
    }

    return formatWhen(bestRaw, timezone);
}

export default function PostOverview({
    postId,
    title,
    status,
    platforms,
    language,
    templateMeta,
    workspaces,
    publishUrl,
    postsyncerReady,
    publishState,
    publishRetryable,
    publishProgress,
    reconcileUrl,
    approvalState,
    timezone,
    needsConfirmAsk,
    postsyncer,
    handles,
}: Props) {
    const effectiveTimezone = timezone.trim() || DEFAULT_TIMEZONE;
    const studioStatus = mapStudioStatus(status);
    const archived = studioStatus === 'archived';
    const stage = archived
        ? POST_PIPELINE.length - 1
        : Math.max(
              0,
              POST_PIPELINE.findIndex((step) => step.key === studioStatus),
          );
    const [busy, setBusy] = useState(false);
    const groups = publishGroups(postsyncer);
    const hasGroups = groups.length > 0;
    const workspaceBuckets = hasGroups
        ? bucketsFromGroups(groups)
        : (workspaces ?? []);
    const hasWorkspaceBuckets = workspaceBuckets.length > 0;
    const publishBusy = ['queued', 'running'].includes(publishState);
    const showPublishControls =
        !archived &&
        studioStatus !== 'posted' &&
        studioStatus !== 'scheduled' &&
        !hasGroups &&
        (studioStatus === 'draft' || publishState === 'failed');
    const canPublish =
        postsyncerReady &&
        !publishBusy &&
        !hasGroups &&
        (studioStatus === 'draft' ||
            (publishState === 'failed' && publishRetryable));
    const publishDisabledReason = publishBusy
        ? 'A publish job is already queued or running.'
        : publishState === 'failed' && !publishRetryable
          ? 'Reconcile the uncertain PostSyncer create before retrying.'
          : !postsyncerReady
            ? 'Configure PostSyncer in Settings before publishing.'
            : null;
    const publishUncertain = Boolean(
        publishState === 'failed' &&
        publishProgress?.state === 'uncertain' &&
        publishProgress.current !== null &&
        publishProgress.current !== undefined &&
        publishProgress.current.phase !== 'uploading',
    );
    const awaitingApproval = approvalState === 'pending';
    const scheduledAt = earliestWhen(groups, effectiveTimezone);

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
                    <span className="k">Approval</span>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3">
                    {awaitingApproval ? (
                        <span className="badge">
                            Needs review before publishing
                        </span>
                    ) : (
                        <span className="badge">Approved for publishing</span>
                    )}
                    {awaitingApproval && (
                        <Form action={`/posts/${postId}/approve`} method="post">
                            {({ processing }) => (
                                <button
                                    type="submit"
                                    className="advance"
                                    disabled={processing}
                                >
                                    Approve draft
                                </button>
                            )}
                        </Form>
                    )}
                </div>
            </section>
            <section className="pane">
                <div className="pane-head">
                    <span className="k">Status</span>
                </div>
                {hasGroups || hasWorkspaceBuckets ? (
                    <WorkspacePlatformChips
                        buckets={workspaceBuckets}
                        handles={handles}
                    />
                ) : (
                    <PlatformChipRow
                        platforms={platforms}
                        lang={language === 'en' ? 'en' : 'bn'}
                        handles={handles}
                    />
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
                        {publishUncertain ? (
                            <Form
                                action={reconcileUrl}
                                method="post"
                                className="schedule-it"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <label className="schedule-it-label">
                                            <span className="schedule-it-label-row">
                                                Reconcile PostSyncer create
                                            </span>
                                            <input
                                                name="postsyncer_id"
                                                type="text"
                                                inputMode="numeric"
                                                required
                                                placeholder="PostSyncer post ID"
                                                disabled={processing}
                                            />
                                        </label>
                                        <button
                                            type="submit"
                                            className="advance"
                                            disabled={processing}
                                        >
                                            Verify and reconcile
                                        </button>
                                        {errors.postsyncer_id && (
                                            <p className="schedule-it-error">
                                                {errors.postsyncer_id}
                                            </p>
                                        )}
                                        <p className="schedule-it-hint">
                                            Find the created post in PostSyncer,
                                            verify its content, then enter its
                                            ID.
                                        </p>
                                    </>
                                )}
                            </Form>
                        ) : archived ? (
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
                        ) : showPublishControls ? (
                            <PublishDialog
                                disabled={!canPublish}
                                disabledReason={publishDisabledReason}
                                publishState={publishState}
                                publishUrl={publishUrl}
                                entityLabel="post"
                                needsConfirmAsk={needsConfirmAsk}
                                retryOnly={publishState === 'failed'}
                                showStatus={false}
                            />
                        ) : (
                            <span className="badge">
                                🗓️ Scheduled
                                {scheduledAt
                                    ? ` · ${scheduledAt} ${effectiveTimezone}`
                                    : ''}
                            </span>
                        )}
                    </div>

                    <WorkspaceScheduleLog
                        buckets={workspaceBuckets}
                        formatWhen={(value) =>
                            formatWhen(value, effectiveTimezone)
                        }
                        timezone={effectiveTimezone}
                        handles={handles}
                        heading={
                            studioStatus === 'posted'
                                ? 'Published posts'
                                : 'Scheduled posts'
                        }
                        empty={
                            studioStatus === 'scheduled' && !hasGroups
                                ? 'Marked scheduled, but Content Machine has no PostSyncer ids for this post yet.'
                                : undefined
                        }
                    />
                </div>
            </section>
            {templateMeta && (
                <section className="pane">
                    <div className="pane-head">
                        <span className="k">Template</span>
                    </div>
                    <Link
                        href={showTemplate.url(templateMeta.letter)}
                        aria-label={`Open ${templateMeta.label} details`}
                        className="group m-4 flex items-center overflow-hidden rounded-xl border border-[var(--line)] bg-[var(--bg3)] text-left no-underline transition hover:-translate-y-0.5 hover:border-[var(--line-strong)] hover:shadow-[var(--shadow)]"
                    >
                        <TemplatePreview
                            src={templateMeta.preview_url}
                            alt={`${templateMeta.label} preview`}
                            letter={templateMeta.letter}
                            className="size-20 shrink-0"
                        />
                        <span className="min-w-0 flex-1 px-3 py-2.5">
                            <span className="flex items-center gap-1 text-[11px] font-medium tracking-wide text-[var(--ink-faint)] uppercase">
                                Template reference
                                <ArrowUpRight className="size-3.5" />
                            </span>
                            <span className="mt-0.5 block truncate text-sm font-semibold text-[var(--ink)]">
                                {templateMeta.label}
                                <span className="font-normal text-[var(--ink-soft)]">
                                    {' · '}
                                    {templateMeta.name}
                                </span>
                            </span>
                            <span className="mt-1 block truncate text-xs text-[var(--ink-soft)]">
                                <span className="font-medium text-[var(--ink)]">
                                    Type:
                                </span>{' '}
                                {templateMeta.visual_identity}
                            </span>
                        </span>
                    </Link>
                </section>
            )}
        </div>
    );
}
