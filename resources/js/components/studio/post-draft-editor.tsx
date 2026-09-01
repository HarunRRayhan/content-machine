import { router } from '@inertiajs/react';
import type { FormDataConvertible } from '@inertiajs/core';
import { useEffect, useState } from 'react';
import type {
    CaptionGroup,
    CaptionPlatform,
} from '@/components/content/captions-panel';

type EditableCaptionGroup = CaptionGroup & {
    lang?: string | null;
};

type EditableField = 'title' | 'caption' | 'first_comment';

type Props = {
    postId: number;
    title: string;
    body: string | null;
    groups: EditableCaptionGroup[];
    editable: boolean;
};

export default function PostDraftEditor({
    postId,
    title,
    body,
    groups,
    editable,
}: Props) {
    const [draftTitle, setDraftTitle] = useState(title);
    const [draftBody, setDraftBody] = useState(body ?? '');
    const [draftGroups, setDraftGroups] = useState(groups);
    const [saving, setSaving] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});

    useEffect(() => {
        setDraftTitle(title);
        setDraftBody(body ?? '');
        setDraftGroups(groups);
    }, [body, groups, title]);

    if (!editable) {
        return null;
    }

    function updateCaption(
        groupIndex: number,
        platformIndex: number,
        field: EditableField,
        value: string,
    ) {
        setDraftGroups((current) =>
            current.map((group, currentGroupIndex) => {
                if (currentGroupIndex !== groupIndex) {
                    return group;
                }

                return {
                    ...group,
                    platforms: group.platforms.map(
                        (platform, currentPlatformIndex) =>
                            currentPlatformIndex === platformIndex
                                ? { ...platform, [field]: value }
                                : platform,
                    ),
                };
            }),
        );
    }

    function saveDraft(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        setSaving(true);
        setErrors({});

        router.patch(
            `/posts/${postId}`,
            {
                title: draftTitle,
                body: draftBody,
                captions: draftGroups as unknown as FormDataConvertible,
            },
            {
                preserveScroll: true,
                onError: (nextErrors) =>
                    setErrors(nextErrors as Record<string, string>),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <section className="pane mb-5">
            <div className="pane-head">
                <span className="k">✎ Edit draft</span>
            </div>
            <form className="studio-form" onSubmit={saveDraft}>
                <label htmlFor="post-draft-title">Title</label>
                <input
                    id="post-draft-title"
                    value={draftTitle}
                    onChange={(event) => setDraftTitle(event.target.value)}
                    maxLength={255}
                    required
                />
                {errors.title && (
                    <p className="text-sm text-red-600">{errors.title}</p>
                )}

                <label htmlFor="post-draft-body">Post body</label>
                <textarea
                    id="post-draft-body"
                    value={draftBody}
                    onChange={(event) => setDraftBody(event.target.value)}
                    rows={5}
                    maxLength={20000}
                />
                {errors.body && (
                    <p className="text-sm text-red-600">{errors.body}</p>
                )}

                {draftGroups.map((group, groupIndex) =>
                    group.platforms.map((platform, platformIndex) => (
                        <CaptionEditor
                            key={`${group.part ?? 'main'}-${groupIndex}-${platform.name}-${platformIndex}`}
                            idPrefix={`${groupIndex}-${platformIndex}`}
                            group={group}
                            platform={platform}
                            onChange={(field, value) =>
                                updateCaption(
                                    groupIndex,
                                    platformIndex,
                                    field,
                                    value,
                                )
                            }
                        />
                    )),
                )}

                {errors.captions && (
                    <p className="text-sm text-red-600">{errors.captions}</p>
                )}

                <button
                    type="submit"
                    className="advance"
                    disabled={saving}
                >
                    {saving ? 'Saving...' : 'Save draft'}
                </button>
            </form>
        </section>
    );
}

function CaptionEditor({
    idPrefix,
    group,
    platform,
    onChange,
}: {
    idPrefix: string;
    group: EditableCaptionGroup;
    platform: CaptionPlatform;
    onChange: (field: EditableField, value: string) => void;
}) {
    const label = group.lang ? ` · ${group.lang}` : '';

    return (
        <fieldset className="grid gap-2 rounded-lg border border-border p-3">
            <legend className="px-1 text-xs font-medium uppercase tracking-wide text-muted-foreground">
                {platform.name}
                {label}
            </legend>
            <label htmlFor={`caption-title-${idPrefix}`}>Caption title</label>
            <input
                id={`caption-title-${idPrefix}`}
                value={platform.title}
                onChange={(event) => onChange('title', event.target.value)}
            />
            <label htmlFor={`caption-${idPrefix}`}>Caption</label>
            <textarea
                id={`caption-${idPrefix}`}
                value={platform.caption}
                onChange={(event) => onChange('caption', event.target.value)}
                rows={5}
                required
            />
            <label htmlFor={`first-comment-${idPrefix}`}>
                First comment
            </label>
            <textarea
                id={`first-comment-${idPrefix}`}
                value={platform.first_comment}
                onChange={(event) =>
                    onChange('first_comment', event.target.value)
                }
                rows={3}
            />
        </fieldset>
    );
}
