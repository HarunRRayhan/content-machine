import { router } from '@inertiajs/react';
import { useState } from 'react';
import { studioPostStatus } from '@/lib/platform-meta';

const POST_PIPELINE = [
    { key: 'draft', label: 'Draft', prompt: null },
    { key: 'scheduled', label: 'Scheduled', prompt: '🗓️ Scheduled?' },
    { key: 'posted', label: 'Posted', prompt: '📮 Posted?' },
] as const;

type PostImage = {
    filename: string;
    url: string | null;
};

type Props = {
    postId: number;
    title: string;
    status: string;
    platforms: string[];
    images?: PostImage[];
};

function mapStudioStatus(status: string): string {
    const mapped = studioPostStatus(status);

    if (mapped === 'archived') {
        return 'archived';
    }

    return mapped;
}

export default function PostOverview({
    postId,
    title,
    status,
    platforms,
    images = [],
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
                        ) : stage >= POST_PIPELINE.length - 1 ? (
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
                                {stage > 0 && (
                                    <button
                                        type="button"
                                        className="undo"
                                        disabled={busy}
                                        onClick={() =>
                                            advanceStatus(
                                                POST_PIPELINE[stage - 1].key,
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
                                        advanceStatus(
                                            POST_PIPELINE[stage + 1].key,
                                        )
                                    }
                                >
                                    {POST_PIPELINE[stage + 1].prompt}
                                </button>
                                {stage > 0 && (
                                    <button
                                        type="button"
                                        className="undo"
                                        disabled={busy}
                                        onClick={() =>
                                            advanceStatus(
                                                POST_PIPELINE[stage - 1].key,
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
                <div className="pane-head">
                    <span className="k">Images</span>
                </div>
                {images.length === 0 ? (
                    <p className="empty">No images on this post yet.</p>
                ) : (
                    <div className="post-images-gallery">
                        {images.map((image) =>
                            image.url ? (
                                <img
                                    key={image.filename}
                                    src={image.url}
                                    alt={image.filename}
                                />
                            ) : (
                                <div
                                    key={image.filename}
                                    className="mock-img-missing"
                                >
                                    {image.filename}
                                </div>
                            ),
                        )}
                    </div>
                )}
            </section>
        </div>
    );
}
