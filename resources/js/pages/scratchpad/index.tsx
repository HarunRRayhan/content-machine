import { Form, Head, Link } from '@inertiajs/react';
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
import { index, show } from '@/routes/dashboard/scratchpad';

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
    return (
        <>
            <Head title="Scratch Pad" />

            <div className="flex h-full flex-1 flex-col gap-8 rounded-xl p-4">
                <Heading
                    title="Scratch Pad"
                    description="Capture an idea the instant it occurs to you. Sort it out later."
                />

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Form
                        {...ScratchpadController.store.form()}
                        resetOnSuccess
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
                                        placeholder="What's on your mind?"
                                        rows={3}
                                    />
                                    <InputError message={errors.body} />
                                </div>

                                <Button disabled={processing}>Save note</Button>
                            </>
                        )}
                    </Form>
                </div>

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Form
                        {...ScratchpadController.storePhoto.form()}
                        resetOnSuccess
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

                    <div className="border-t pt-4">
                        <ScratchpadVoiceRecorder />
                    </div>
                </div>

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
                            </Link>
                            <ScratchpadEntryMedia
                                attachments={entry.attachments}
                            />
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
