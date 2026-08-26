import { Form, Head, Link, router } from '@inertiajs/react';
import { Image, Link2, Mic, NotebookPen, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import ScratchpadController from '@/actions/App/Http/Controllers/Scratchpad/ScratchpadController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ScratchpadEntryMedia } from '@/components/scratchpad-entry-media';
import type { ScratchpadAttachment } from '@/components/scratchpad-entry-media';
import { ScratchpadVoiceRecorder } from '@/components/scratchpad-voice-recorder';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { index, show } from '@/routes/scratchpad';

type EntryLink = {
    url: string | null;
    resolved_via: string | null;
    thumbnail_url: string | null;
};

type EntrySummary = {
    id: number;
    public_id: string;
    kind: string;
    status: string;
    title: string | null;
    preview: string | null;
    captured_at: string;
    language: string | null;
    attachments: ScratchpadAttachment[];
    link: EntryLink | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedEntries = {
    data: EntrySummary[];
    links: PaginationLink[];
    total: number;
};

type PageProps = {
    entries: PaginatedEntries;
};

const statusVariant: Record<string, 'default' | 'secondary' | 'outline'> = {
    new: 'default',
    triaged: 'secondary',
    dropped: 'outline',
};

type CaptureMode = 'closed' | 'picking' | 'text' | 'link' | 'photo' | 'voice';

const CAPTURE_TYPES: {
    mode: Exclude<CaptureMode, 'closed' | 'picking'>;
    label: string;
    icon: typeof NotebookPen;
}[] = [
    { mode: 'text', label: 'Text note', icon: NotebookPen },
    { mode: 'link', label: 'Link', icon: Link2 },
    { mode: 'photo', label: 'Photo', icon: Image },
    { mode: 'voice', label: 'Voice', icon: Mic },
];

/**
 * Laravel's paginator labels ("&laquo; Previous", "Next &raquo;") come as
 * HTML-entity-encoded strings meant for a Blade `{!! !!}` echo. Rendering
 * them safely here just means decoding the two entities it actually uses,
 * rather than reaching for dangerouslySetInnerHTML.
 */
function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

export default function ScratchpadIndex({ entries }: PageProps) {
    const [mode, setMode] = useState<CaptureMode>('closed');

    return (
        <>
            <Head title="Scratch Pad" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Scratch Pad"
                    description="Capture an idea the instant it occurs to you. Sort it out later."
                />

                {mode === 'closed' && (
                    <Button
                        type="button"
                        className="w-fit"
                        onClick={() => setMode('picking')}
                    >
                        <Plus /> New capture
                    </Button>
                )}

                {mode === 'picking' && (
                    <div className="flex max-w-2xl flex-wrap items-center gap-2 rounded-lg border p-4">
                        {CAPTURE_TYPES.map(
                            ({ mode: type, label, icon: Icon }) => (
                                <Button
                                    key={type}
                                    type="button"
                                    variant="outline"
                                    onClick={() => setMode(type)}
                                >
                                    <Icon /> {label}
                                </Button>
                            ),
                        )}
                        <Button
                            type="button"
                            variant="ghost"
                            className="ml-auto"
                            onClick={() => setMode('closed')}
                        >
                            Cancel
                        </Button>
                    </div>
                )}

                {mode === 'text' && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <button
                            type="button"
                            onClick={() => setMode('picking')}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            &larr; Choose a different type
                        </button>

                        <Form
                            {...ScratchpadController.store.form()}
                            resetOnSuccess
                            onSuccess={() => setMode('closed')}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="body">New note</Label>
                                        <Textarea
                                            id="body"
                                            name="body"
                                            required
                                            autoFocus
                                            placeholder="What's on your mind?"
                                            rows={3}
                                        />
                                        <InputError message={errors.body} />
                                    </div>

                                    <Button disabled={processing}>
                                        Save note
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {mode === 'link' && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <button
                            type="button"
                            onClick={() => setMode('picking')}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            &larr; Choose a different type
                        </button>

                        <Form
                            {...ScratchpadController.storeLink.form()}
                            resetOnSuccess
                            onSuccess={() => setMode('closed')}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="url">
                                            Capture a link
                                        </Label>
                                        <Input
                                            id="url"
                                            type="url"
                                            name="url"
                                            required
                                            autoFocus
                                            placeholder="https://..."
                                        />
                                        <InputError message={errors.url} />
                                    </div>

                                    <Button disabled={processing}>
                                        Save link
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {mode === 'photo' && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <button
                            type="button"
                            onClick={() => setMode('picking')}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            &larr; Choose a different type
                        </button>

                        <Form
                            {...ScratchpadController.storePhoto.form()}
                            resetOnSuccess
                            onSuccess={() => setMode('closed')}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="photo">
                                            Capture a photo
                                        </Label>
                                        <Input
                                            id="photo"
                                            type="file"
                                            name="photo"
                                            accept="image/*"
                                            required
                                            autoFocus
                                        />
                                        <InputError message={errors.photo} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="caption">
                                            Caption (optional)
                                        </Label>
                                        <Input
                                            id="caption"
                                            name="caption"
                                            placeholder="What's in the photo?"
                                        />
                                        <InputError message={errors.caption} />
                                    </div>

                                    <Button disabled={processing}>
                                        Save photo
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}

                {mode === 'voice' && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <button
                            type="button"
                            onClick={() => setMode('picking')}
                            className="text-sm text-muted-foreground hover:underline"
                        >
                            &larr; Choose a different type
                        </button>

                        <ScratchpadVoiceRecorder
                            onSaved={() => setMode('closed')}
                        />
                    </div>
                )}

                <div className="space-y-3">
                    {entries.data.length === 0 && (
                        <p className="text-sm text-muted-foreground">
                            Nothing captured yet.
                        </p>
                    )}

                    {entries.data.map((entry) => (
                        <div
                            key={entry.id}
                            className="space-y-2 rounded-lg border p-3 transition-colors hover:bg-accent"
                        >
                            {/* audio's own <audio controls> is interactive
                                content, so it can't nest inside this <a>
                                without breaking clicks on its play button;
                                the link only wraps the non-interactive
                                summary text below. */}
                            <Link
                                href={show.url(entry.id)}
                                className="block space-y-1"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm text-muted-foreground">
                                        {new Date(
                                            entry.captured_at,
                                        ).toLocaleString()}
                                    </p>
                                    <div className="flex items-center gap-2">
                                        <Badge variant="outline">
                                            {entry.kind}
                                        </Badge>
                                        {entry.language && (
                                            <Badge variant="outline">
                                                {entry.language}
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={
                                                statusVariant[entry.status] ??
                                                'outline'
                                            }
                                        >
                                            {entry.status}
                                        </Badge>
                                    </div>
                                </div>
                                {entry.title && (
                                    <p className="font-medium">{entry.title}</p>
                                )}
                                {entry.preview && (
                                    <p className="text-sm text-muted-foreground">
                                        {entry.preview}
                                    </p>
                                )}
                                {entry.link?.url && (
                                    <p className="truncate text-sm text-muted-foreground">
                                        🔗 {entry.link.url}
                                        {entry.link.resolved_via
                                            ? ` (${entry.link.resolved_via})`
                                            : ' (resolving...)'}
                                    </p>
                                )}
                            </Link>
                            <ScratchpadEntryMedia
                                attachments={entry.attachments}
                            />
                            {entry.status !== 'triaged' && (
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="ghost"
                                        className="text-muted-foreground hover:text-destructive"
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    "Delete this entry? This can't be undone.",
                                                )
                                            ) {
                                                router.delete(
                                                    ScratchpadController.destroy.url(
                                                        entry.id,
                                                    ),
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
                                    >
                                        <Trash2 /> Delete
                                    </Button>
                                </div>
                            )}
                        </div>
                    ))}
                </div>

                {entries.links.length > 3 && (
                    <nav className="flex flex-wrap items-center gap-1">
                        {entries.links.map((link, position) => (
                            <Button
                                key={`${link.label}-${position}`}
                                variant={link.active ? 'default' : 'outline'}
                                size="sm"
                                disabled={link.url === null}
                                asChild={link.url !== null}
                            >
                                {link.url !== null ? (
                                    <Link href={link.url} preserveScroll>
                                        {paginationLabel(link.label)}
                                    </Link>
                                ) : (
                                    <span>{paginationLabel(link.label)}</span>
                                )}
                            </Button>
                        ))}
                    </nav>
                )}
            </div>
        </>
    );
}

ScratchpadIndex.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Scratch Pad', href: index() },
    ],
};
