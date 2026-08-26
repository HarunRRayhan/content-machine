import { router } from '@inertiajs/react';
import { useMemo, useState } from 'react';

type TalkingPoint = {
    label: string;
    text: string;
};

const PIPELINE = [
    { key: 'pending', label: 'Pending', prompt: null },
    { key: 'ready', label: 'Ready', prompt: '✅ Ready?' },
    { key: 'recorded', label: 'Recorded', prompt: '🎥 Recorded?' },
    { key: 'scheduled', label: 'Scheduled', prompt: '🗓️ Scheduled?' },
    { key: 'posted', label: 'Published', prompt: '📮 Published?' },
] as const;

type Props = {
    videoId: number;
    title: string;
    status: string;
    lang: string;
    length: string;
    points: TalkingPoint[];
    storageKey: string;
};

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
}: Props) {
    const [checks, setChecks] = useState<Set<number>>(() =>
        readChecks(storageKey),
    );
    const [busy, setBusy] = useState(false);

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
        setBusy(true);
        router.patch(
            `/dashboard/videos/${videoId}`,
            { title, status: nextStatus },
            {
                preserveScroll: true,
                onFinish: () => setBusy(false),
            },
        );
    }

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

                            return [node, <span key={`${step.key}-conn`} className="conn" />];
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
                        ) : stage >= PIPELINE.length - 1 ? (
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
                </div>
            </section>

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
                    className={`alldone${total > 0 && doneCount === total ? ' show' : ''}`}
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
