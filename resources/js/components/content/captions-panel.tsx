import { useMemo, useState } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';

export type CaptionPlatform = {
    name: string;
    title: string;
    caption: string;
    first_comment: string;
    images: string[];
    thread: unknown[];
};

export type CaptionGroup = {
    part: string | null;
    platforms: CaptionPlatform[];
};

type Props = {
    groups: CaptionGroup[];
    emptyLabel?: string;
};

async function copyText(text: string) {
    if (!text) {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // Clipboard can fail without a secure context; ignore quietly.
    }
}

/**
 * Studio-style captions panel: one section per part, platform tabs inside
 * each section, copy buttons for title / caption / first comment.
 */
export default function CaptionsPanel({
    groups,
    emptyLabel = 'No captions yet.',
}: Props) {
    const [activeByGroup, setActiveByGroup] = useState<Record<number, number>>(
        {},
    );

    const hasAny = useMemo(
        () => groups.some((group) => group.platforms.length > 0),
        [groups],
    );

    if (!hasAny) {
        return (
            <p className="text-sm text-muted-foreground">{emptyLabel}</p>
        );
    }

    return (
        <div className="space-y-6">
            {groups.map((group, groupIndex) => {
                if (group.platforms.length === 0) {
                    return null;
                }

                const active =
                    activeByGroup[groupIndex] ??
                    (group.platforms.length > 0 ? 0 : -1);
                const platform = group.platforms[active];

                return (
                    <section
                        key={`${group.part ?? 'main'}-${groupIndex}`}
                        className="space-y-3 rounded-lg border p-4"
                    >
                        <div className="flex flex-wrap items-center gap-2">
                            <p className="text-sm font-medium">Captions</p>
                            {group.part && (
                                <Badge variant="outline">{group.part}</Badge>
                            )}
                        </div>

                        {group.platforms.length > 1 && (
                            <div className="flex flex-wrap gap-1">
                                {group.platforms.map((item, platformIndex) => (
                                    <Button
                                        key={item.name}
                                        type="button"
                                        size="sm"
                                        variant={
                                            platformIndex === active
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            setActiveByGroup((prev) => ({
                                                ...prev,
                                                [groupIndex]: platformIndex,
                                            }))
                                        }
                                    >
                                        {item.name}
                                    </Button>
                                ))}
                            </div>
                        )}

                        {platform && (
                            <div className="space-y-4">
                                <CaptionField
                                    label="Title"
                                    value={platform.title}
                                />
                                <CaptionField
                                    label="Caption"
                                    value={platform.caption}
                                    multiline
                                />
                                <CaptionField
                                    label="First comment"
                                    value={platform.first_comment}
                                    multiline
                                />
                                {platform.images.length > 0 && (
                                    <div className="space-y-1">
                                        <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                                            Images
                                        </p>
                                        <ul className="list-inside list-disc text-sm">
                                            {platform.images.map((name) => (
                                                <li key={name}>{name}</li>
                                            ))}
                                        </ul>
                                    </div>
                                )}
                            </div>
                        )}
                    </section>
                );
            })}
        </div>
    );
}

function CaptionField({
    label,
    value,
    multiline = false,
}: {
    label: string;
    value: string;
    multiline?: boolean;
}) {
    return (
        <div className="space-y-1">
            <div className="flex items-center justify-between gap-2">
                <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                    {label}
                </p>
                <Button
                    type="button"
                    size="sm"
                    variant="ghost"
                    disabled={!value}
                    onClick={() => copyText(value)}
                >
                    Copy
                </Button>
            </div>
            {value ? (
                multiline ? (
                    <pre className="whitespace-pre-wrap rounded-md bg-muted/50 p-3 text-sm">
                        {value}
                    </pre>
                ) : (
                    <p className="rounded-md bg-muted/50 p-3 text-sm">{value}</p>
                )
            ) : (
                <p className="text-sm text-muted-foreground">—</p>
            )}
        </div>
    );
}
