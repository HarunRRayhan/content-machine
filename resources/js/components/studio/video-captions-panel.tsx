import { useState } from 'react';
import type { CaptionGroup } from '@/components/content/captions-panel';

async function copyText(text: string): Promise<void> {
    if (!text) {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // ignore clipboard failures
    }
}

type Props = {
    groups: CaptionGroup[];
};

export default function VideoCaptionsPanel({ groups }: Props) {
    const [activeByGroup, setActiveByGroup] = useState<Record<number, number>>(
        {},
    );

    const visible = groups.filter((group) => group.platforms.length > 0);

    if (visible.length === 0) {
        return <p className="empty">No captions yet.</p>;
    }

    return (
        <div className="space-y-4">
            {visible.map((group, groupIndex) => {
                const active =
                    activeByGroup[groupIndex] ??
                    (group.platforms.length > 0 ? 0 : -1);
                const platform = group.platforms[active];
                const tabbed = group.platforms.length > 1;

                return (
                    <section
                        key={`${group.part ?? 'main'}-${groupIndex}`}
                        className="pane"
                    >
                        <div className="pane-head">
                            <span className="k">
                                Captions
                                {group.part ? ` · ${group.part}` : ''}
                            </span>
                        </div>

                        {tabbed && (
                            <div className="cap-tabbar" role="tablist">
                                {group.platforms.map((item, platformIndex) => (
                                    <button
                                        key={item.name}
                                        type="button"
                                        role="tab"
                                        aria-selected={platformIndex === active}
                                        onClick={() =>
                                            setActiveByGroup((prev) => ({
                                                ...prev,
                                                [groupIndex]: platformIndex,
                                            }))
                                        }
                                    >
                                        {item.name}
                                    </button>
                                ))}
                            </div>
                        )}

                        {platform && (
                            <div className="cap">
                                <div className="cap-h">
                                    <span className="cap-n">
                                        {platform.name}
                                    </span>
                                </div>

                                <CaptionField
                                    label="Title"
                                    value={platform.title}
                                />
                                <CaptionField
                                    label="Caption"
                                    value={platform.caption}
                                />
                                <CaptionField
                                    label="First comment"
                                    value={platform.first_comment}
                                />

                                {platform.images.length > 0 && (
                                    <>
                                        <span className="cap-l">Images</span>
                                        <ul className="cap-b">
                                            {platform.images.map((name) => (
                                                <li key={name}>{name}</li>
                                            ))}
                                        </ul>
                                    </>
                                )}
                            </div>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

function CaptionField({ label, value }: { label: string; value: string }) {
    return (
        <>
            <div className="cap-h">
                <span className="cap-l">{label}</span>
                <button
                    type="button"
                    className="cap-copy"
                    disabled={!value}
                    onClick={() => copyText(value)}
                >
                    Copy
                </button>
            </div>
            {label === 'Title' ? (
                <div className="cap-t">{value || '—'}</div>
            ) : (
                <div className="cap-b">{value || '—'}</div>
            )}
        </>
    );
}
