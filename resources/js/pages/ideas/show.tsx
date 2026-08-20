import { Form, Head, Link } from '@inertiajs/react';
import IdeasController from '@/actions/App/Http/Controllers/Ideas/IdeasController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { index } from '@/routes/dashboard/ideas';
import { show as showPost } from '@/routes/dashboard/posts';
import { show as showVideo } from '@/routes/dashboard/videos';

type PromotedEntity = {
    id: number;
    kind: string;
    human_id: string;
    title: string;
    status: string;
};

type IdeaDetail = {
    id: number;
    human_id: string;
    kind: string;
    title: string;
    slug: string;
    score: number | null;
    trend: string | null;
    rationale: string | null;
    body: string | null;
    status: string;
    drop_reason: string | null;
    created_at: string | null;
    promoted_to: PromotedEntity | null;
};

type PageProps = {
    idea: IdeaDetail;
};

const statusVariant: Record<string, 'default' | 'secondary' | 'outline'> = {
    open: 'default',
    promoted: 'secondary',
    dropped: 'outline',
};

export default function IdeaShow({ idea }: PageProps) {
    const isOpen = idea.status === 'open';
    const isDropped = idea.status === 'dropped';

    return (
        <>
            <Head title={idea.title} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Back to Ideas
                </Link>

                <Heading
                    title={idea.title}
                    description={`${idea.human_id} · ${idea.kind}`}
                />

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{idea.human_id}</Badge>
                    <Badge variant="outline">{idea.kind}</Badge>
                    {idea.trend && (
                        <Badge variant="secondary">{idea.trend}</Badge>
                    )}
                    <Badge variant={statusVariant[idea.status] ?? 'outline'}>
                        {idea.status}
                    </Badge>
                </div>

                {isDropped && idea.drop_reason && (
                    <div className="max-w-2xl rounded-lg border border-destructive/50 bg-destructive/5 p-4">
                        <p className="text-sm font-medium">Drop reason</p>
                        <p className="text-sm text-muted-foreground">
                            {idea.drop_reason}
                        </p>
                    </div>
                )}

                {idea.promoted_to && (
                    <div className="max-w-2xl rounded-lg border p-4">
                        <p className="text-sm text-muted-foreground">
                            Promoted to
                        </p>
                        <Link
                            href={
                                idea.promoted_to.kind === 'video'
                                    ? showVideo.url(idea.promoted_to.id)
                                    : showPost.url(idea.promoted_to.id)
                            }
                            className="font-medium hover:underline"
                        >
                            {idea.promoted_to.human_id}:{' '}
                            {idea.promoted_to.title}
                        </Link>
                        <p className="text-sm text-muted-foreground">
                            {idea.promoted_to.status}
                        </p>
                    </div>
                )}

                {isOpen && (
                    <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                        <Heading
                            variant="small"
                            title="Promote this idea"
                            description={`Create a draft ${idea.kind} shell from it.`}
                        />

                        <Form {...IdeasController.promote.form(idea.id)}>
                            {({ processing }) => (
                                <Button type="submit" disabled={processing}>
                                    {idea.kind === 'video'
                                        ? 'Promote to video'
                                        : 'Promote to post'}
                                </Button>
                            )}
                        </Form>
                    </div>
                )}

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Heading variant="small" title="Edit idea" />

                    <Form
                        {...IdeasController.update.form(idea.id)}
                        className="space-y-4"
                    >
                        {({ processing, errors }) => (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="title">Title</Label>
                                    <Input
                                        id="title"
                                        name="title"
                                        required
                                        defaultValue={idea.title}
                                    />
                                    <InputError message={errors.title} />
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
                                            defaultValue={idea.score ?? ''}
                                        />
                                        <InputError message={errors.score} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="trend">Trend</Label>
                                        <select
                                            id="trend"
                                            name="trend"
                                            defaultValue={idea.trend ?? ''}
                                            className="flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                        >
                                            <option value="">Unset</option>
                                            <option value="evergreen">
                                                Evergreen
                                            </option>
                                            <option value="seasonal">
                                                Seasonal
                                            </option>
                                        </select>
                                        <InputError message={errors.trend} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="rationale">Rationale</Label>
                                    <Textarea
                                        id="rationale"
                                        name="rationale"
                                        rows={3}
                                        defaultValue={idea.rationale ?? ''}
                                    />
                                    <InputError message={errors.rationale} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="body">Body</Label>
                                    <Textarea
                                        id="body"
                                        name="body"
                                        rows={6}
                                        defaultValue={idea.body ?? ''}
                                    />
                                    <InputError message={errors.body} />
                                </div>

                                <Button disabled={processing}>
                                    Save changes
                                </Button>
                            </>
                        )}
                    </Form>
                </div>

                {!isDropped && (
                    <div className="max-w-2xl space-y-4 rounded-lg border border-destructive/50 p-4">
                        <Heading
                            variant="small"
                            title="Drop this idea"
                            description="This can't be undone from here."
                        />

                        <Form
                            {...IdeasController.drop.form(idea.id)}
                            className="space-y-4"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label htmlFor="drop_reason">
                                            Reason
                                        </Label>
                                        <Textarea
                                            id="drop_reason"
                                            name="drop_reason"
                                            required
                                            rows={2}
                                            placeholder="Why is this idea being dropped?"
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
                                        Drop idea
                                    </Button>
                                </>
                            )}
                        </Form>
                    </div>
                )}
            </div>
        </>
    );
}

IdeaShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Ideas', href: index() },
    ],
};
