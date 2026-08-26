import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ScratchpadController from '@/actions/App/Http/Controllers/Scratchpad/ScratchpadController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { ScratchpadEntryMedia } from '@/components/scratchpad-entry-media';
import type { ScratchpadAttachment } from '@/components/scratchpad-entry-media';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index } from '@/routes/scratchpad';

type TriagedIdea = {
    id: number;
    human_id: string;
    kind: string;
    title: string;
};

type EntryLink = {
    url: string | null;
    resolved_via: string | null;
    thumbnail_url: string | null;
    summarized: boolean;
};

type EntryTranscription = {
    status: string;
    text: string | null;
    language: string | null;
    error_message: string | null;
};

type EntryDetail = {
    id: number;
    public_id: string;
    kind: string;
    status: string;
    source: string;
    language: string | null;
    title: string | null;
    body: string | null;
    captured_at: string;
    drop_reason: string | null;
    attachments: ScratchpadAttachment[];
    link: EntryLink | null;
    transcription: EntryTranscription | null;
    idea: TriagedIdea | null;
};

type TriageSuggestion = {
    target: 'post_idea' | 'video_idea';
    successful: boolean;
    title: string | null;
    score: number | null;
    trend: string | null;
    rationale: string | null;
    error: string | null;
};

type PageProps = {
    entry: EntryDetail;
    suggestion?: TriageSuggestion | null;
};

type TriagePanel = 'post_idea' | 'video_idea' | 'drop' | null;

export default function ScratchpadShow({ entry, suggestion }: PageProps) {
    const [panel, setPanel] = useState<TriagePanel>(null);
    const [suggesting, setSuggesting] = useState(false);

    function requestSuggestion(target: 'post_idea' | 'video_idea') {
        setSuggesting(true);
        router.post(
            ScratchpadController.suggestTriage.url(entry.id),
            { target },
            {
                preserveState: true,
                preserveScroll: true,
                preserveUrl: true,
                only: ['suggestion'],
                onFinish: () => setSuggesting(false),
            },
        );
    }

    const activeSuggestion =
        suggestion && suggestion.target === panel ? suggestion : null;

    return (
        <>
            <Head title={entry.title ?? 'Scratch Pad entry'} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Back to Scratch Pad
                </Link>

                <Heading
                    title={entry.title ?? `Untitled ${entry.kind} note`}
                    description={new Date(entry.captured_at).toLocaleString()}
                />

                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex flex-wrap gap-2">
                        <Badge variant="outline">{entry.kind}</Badge>
                        {entry.language && (
                            <Badge variant="outline">{entry.language}</Badge>
                        )}
                        <Badge variant="secondary">{entry.status}</Badge>
                        <Badge variant="outline">via {entry.source}</Badge>
                    </div>

                    {entry.status !== 'triaged' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
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
                                    );
                                }
                            }}
                        >
                            Delete
                        </Button>
                    )}
                </div>

                <ScratchpadEntryMedia attachments={entry.attachments} />

                {entry.transcription && (
                    <div className="max-w-2xl space-y-1 rounded-lg border p-4">
                        {entry.transcription.status === 'done' && (
                            <p className="whitespace-pre-wrap">
                                {entry.transcription.text}
                            </p>
                        )}
                        {(entry.transcription.status === 'pending' ||
                            entry.transcription.status === 'processing') && (
                            <p className="text-sm text-muted-foreground">
                                Transcribing...
                            </p>
                        )}
                        {entry.transcription.status === 'failed' && (
                            <p className="text-sm text-destructive">
                                Transcription failed
                                {entry.transcription.error_message
                                    ? `: ${entry.transcription.error_message}`
                                    : '.'}
                            </p>
                        )}
                    </div>
                )}

                {entry.link?.url && (
                    <div className="max-w-2xl space-y-1 rounded-lg border p-4">
                        <a
                            href={entry.link.url}
                            target="_blank"
                            rel="noreferrer noopener"
                            className="font-medium break-all text-primary hover:underline"
                        >
                            {entry.link.url}
                        </a>
                        <p className="text-sm text-muted-foreground">
                            {entry.link.resolved_via ?? 'resolving...'}
                        </p>
                    </div>
                )}

                {entry.body && (
                    <div className="max-w-2xl space-y-1 rounded-lg border p-4">
                        {entry.link?.summarized && (
                            <p className="text-xs text-muted-foreground">
                                ✨ AI summary
                            </p>
                        )}
                        <p className="whitespace-pre-wrap">{entry.body}</p>
                    </div>
                )}

                {entry.status === 'new' && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <Heading
                            variant="small"
                            title="Triage this entry"
                            description="Route it into an idea, or drop it."
                        />

                        <div className="flex flex-wrap gap-2">
                            <Button
                                type="button"
                                variant={
                                    panel === 'post_idea'
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() =>
                                    setPanel(
                                        panel === 'post_idea'
                                            ? null
                                            : 'post_idea',
                                    )
                                }
                            >
                                File as post idea
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    panel === 'video_idea'
                                        ? 'default'
                                        : 'outline'
                                }
                                onClick={() =>
                                    setPanel(
                                        panel === 'video_idea'
                                            ? null
                                            : 'video_idea',
                                    )
                                }
                            >
                                File as video idea
                            </Button>
                            <Button
                                type="button"
                                variant={
                                    panel === 'drop' ? 'destructive' : 'outline'
                                }
                                onClick={() =>
                                    setPanel(panel === 'drop' ? null : 'drop')
                                }
                            >
                                Drop
                            </Button>
                        </div>

                        {(panel === 'post_idea' || panel === 'video_idea') && (
                            <Form
                                {...ScratchpadController.triage.form(entry.id)}
                                className="space-y-4 border-t pt-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="target"
                                            value={panel}
                                        />

                                        <div className="flex items-center justify-between">
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={suggesting}
                                                onClick={() =>
                                                    requestSuggestion(panel)
                                                }
                                            >
                                                {suggesting
                                                    ? 'Thinking...'
                                                    : '✨ Suggest with AI'}
                                            </Button>
                                        </div>

                                        {activeSuggestion &&
                                            !activeSuggestion.successful && (
                                                <p className="text-sm text-destructive">
                                                    Couldn't get a suggestion
                                                    {activeSuggestion.error
                                                        ? `: ${activeSuggestion.error}`
                                                        : '.'}
                                                </p>
                                            )}

                                        <div
                                            key={JSON.stringify(
                                                activeSuggestion,
                                            )}
                                            className="space-y-4"
                                        >
                                            <div className="grid gap-2">
                                                <Label htmlFor="title">
                                                    Title
                                                </Label>
                                                <Input
                                                    id="title"
                                                    name="title"
                                                    required
                                                    defaultValue={
                                                        activeSuggestion?.title ??
                                                        ''
                                                    }
                                                    placeholder="What's the idea called?"
                                                />
                                                <InputError
                                                    message={errors.title}
                                                />
                                            </div>

                                            <div className="grid grid-cols-2 gap-4">
                                                <div className="grid gap-2">
                                                    <Label htmlFor="score">
                                                        Score (0-1000)
                                                    </Label>
                                                    <Input
                                                        id="score"
                                                        type="number"
                                                        name="score"
                                                        min={0}
                                                        max={1000}
                                                        defaultValue={
                                                            activeSuggestion?.score ??
                                                            undefined
                                                        }
                                                    />
                                                    <InputError
                                                        message={errors.score}
                                                    />
                                                </div>

                                                <div className="grid gap-2">
                                                    <Label htmlFor="trend">
                                                        Trend
                                                    </Label>
                                                    <select
                                                        id="trend"
                                                        name="trend"
                                                        defaultValue={
                                                            activeSuggestion?.trend ??
                                                            ''
                                                        }
                                                        className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                                    >
                                                        <option value="">
                                                            Unset
                                                        </option>
                                                        <option value="evergreen">
                                                            Evergreen
                                                        </option>
                                                        <option value="seasonal">
                                                            Seasonal
                                                        </option>
                                                    </select>
                                                    <InputError
                                                        message={errors.trend}
                                                    />
                                                </div>
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="rationale">
                                                    Rationale
                                                </Label>
                                                <Textarea
                                                    id="rationale"
                                                    name="rationale"
                                                    rows={3}
                                                    defaultValue={
                                                        activeSuggestion?.rationale ??
                                                        ''
                                                    }
                                                    placeholder="Why does this belong in the pipeline?"
                                                />
                                                <InputError
                                                    message={errors.rationale}
                                                />
                                            </div>
                                        </div>

                                        <Button disabled={processing}>
                                            {panel === 'post_idea'
                                                ? 'File as post idea'
                                                : 'File as video idea'}
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}

                        {panel === 'drop' && (
                            <Form
                                {...ScratchpadController.triage.form(entry.id)}
                                className="space-y-4 border-t pt-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="target"
                                            value="drop"
                                        />

                                        <div className="grid gap-2">
                                            <Label htmlFor="drop_reason">
                                                Reason
                                            </Label>
                                            <Textarea
                                                id="drop_reason"
                                                name="drop_reason"
                                                required
                                                rows={2}
                                                placeholder="Why is this being dropped?"
                                            />
                                            <InputError
                                                message={errors.drop_reason}
                                            />
                                        </div>

                                        <Button
                                            type="submit"
                                            variant="destructive"
                                            disabled={processing}
                                        >
                                            Drop entry
                                        </Button>
                                    </>
                                )}
                            </Form>
                        )}
                    </div>
                )}

                {entry.status === 'triaged' && entry.idea && (
                    <div className="max-w-2xl rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Filed as an idea
                        </p>
                        <Link
                            href={showIdea.url(entry.idea.id)}
                            className="font-medium hover:underline"
                        >
                            {entry.idea.human_id}: {entry.idea.title}
                        </Link>
                    </div>
                )}

                {entry.status === 'dropped' && (
                    <div className="max-w-2xl rounded-lg border border-destructive/50 bg-destructive/5 p-4">
                        <p className="text-sm font-medium">Dropped</p>
                        {entry.drop_reason && (
                            <p className="text-sm text-muted-foreground">
                                {entry.drop_reason}
                            </p>
                        )}
                    </div>
                )}
            </div>
        </>
    );
}

ScratchpadShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Scratch Pad', href: index() },
    ],
};
