import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import ScratchpadController from '@/actions/App/Http/Controllers/Scratchpad/ScratchpadController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index } from '@/routes/dashboard/scratchpad';

type TriagedIdea = {
    id: number;
    human_id: string;
    kind: string;
    title: string;
};

type EntryDetail = {
    id: number;
    public_id: string;
    kind: string;
    status: string;
    source: string;
    title: string | null;
    body: string | null;
    captured_at: string;
    drop_reason: string | null;
    idea: TriagedIdea | null;
};

type PageProps = {
    entry: EntryDetail;
};

type TriagePanel = 'post_idea' | 'video_idea' | 'drop' | null;

export default function ScratchpadShow({ entry }: PageProps) {
    const [panel, setPanel] = useState<TriagePanel>(null);

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

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{entry.kind}</Badge>
                    <Badge variant="secondary">{entry.status}</Badge>
                    <Badge variant="outline">via {entry.source}</Badge>
                </div>

                {entry.body && (
                    <div className="max-w-2xl rounded-lg border p-4">
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

                                        <div className="grid gap-2">
                                            <Label htmlFor="title">Title</Label>
                                            <Input
                                                id="title"
                                                name="title"
                                                required
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
                                                    defaultValue=""
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
                                                placeholder="Why does this belong in the pipeline?"
                                            />
                                            <InputError
                                                message={errors.rationale}
                                            />
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
