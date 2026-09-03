import type { FormDataConvertible } from '@inertiajs/core';
import { router } from '@inertiajs/react';
import { Pencil } from 'lucide-react';
import { useState } from 'react';
import type {
    CaptionGroup,
    CaptionPlatform,
} from '@/components/content/captions-panel';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

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
    const [open, setOpen] = useState(false);

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
                onSuccess: () => setOpen(false),
                onError: (nextErrors) =>
                    setErrors(nextErrors as Record<string, string>),
                onFinish: () => setSaving(false),
            },
        );
    }

    function openEditor() {
        setDraftTitle(title);
        setDraftBody(body ?? '');
        setDraftGroups(groups);
        setErrors({});
        setOpen(true);
    }

    return (
        <>
            <div className="flex justify-end pb-4">
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    aria-label="Edit draft"
                    title="Edit draft"
                    onClick={openEditor}
                >
                    <Pencil className="h-4 w-4" />
                    <span className="sr-only">Edit draft</span>
                </Button>
            </div>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                    <div className="studio-page min-h-0">
                        <DialogHeader className="pr-8">
                            <DialogTitle>Edit draft</DialogTitle>
                            <DialogDescription>
                                Update the draft content and platform captions.
                            </DialogDescription>
                        </DialogHeader>

                        <form className="studio-form" onSubmit={saveDraft}>
                            <label htmlFor="post-draft-title">Title</label>
                            <input
                                id="post-draft-title"
                                value={draftTitle}
                                onChange={(event) =>
                                    setDraftTitle(event.target.value)
                                }
                                maxLength={255}
                                required
                            />
                            {errors.title && (
                                <p className="text-sm text-red-600">
                                    {errors.title}
                                </p>
                            )}

                            <label htmlFor="post-draft-body">Post body</label>
                            <textarea
                                id="post-draft-body"
                                value={draftBody}
                                onChange={(event) =>
                                    setDraftBody(event.target.value)
                                }
                                rows={5}
                                maxLength={20000}
                            />
                            {errors.body && (
                                <p className="text-sm text-red-600">
                                    {errors.body}
                                </p>
                            )}

                            {draftGroups.map((group, groupIndex) =>
                                group.platforms.map(
                                    (platform, platformIndex) => (
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
                                    ),
                                ),
                            )}

                            {errors.captions && (
                                <p className="text-sm text-red-600">
                                    {errors.captions}
                                </p>
                            )}

                            <DialogFooter className="pt-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        disabled={saving}
                                    >
                                        Cancel
                                    </Button>
                                </DialogClose>
                                <Button type="submit" disabled={saving}>
                                    {saving ? 'Saving...' : 'Save draft'}
                                </Button>
                            </DialogFooter>
                        </form>
                    </div>
                </DialogContent>
            </Dialog>
        </>
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
            <legend className="px-1 text-xs font-medium tracking-wide text-muted-foreground uppercase">
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
            <label htmlFor={`first-comment-${idPrefix}`}>First comment</label>
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
