import { Form, Head, Link } from '@inertiajs/react';
import IdeasController from '@/actions/App/Http/Controllers/Ideas/IdeasController';
import InputError from '@/components/input-error';
import { postShowUrl, videoShowUrl } from '@/lib/content-urls';
import { scoreBand, trendLabel } from '@/lib/studio-meta';
import { index as ideasIndex } from '@/routes/dashboard/ideas';
import { index as postsIndex } from '@/routes/posts';
import { index as videosIndex } from '@/routes/videos';

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

function parentIndex(kind: string) {
    if (kind === 'video') {
        return videosIndex();
    }

    if (kind === 'post') {
        return postsIndex();
    }

    return ideasIndex();
}

function ideasListHref(kind: string) {
    if (kind === 'video') {
        return videosIndex.url({ query: { status: 'ideation' } });
    }

    if (kind === 'post') {
        return postsIndex.url({ query: { status: 'ideation' } });
    }

    return ideasIndex();
}

function parentLabel(kind: string) {
    if (kind === 'video') {
        return 'Videos';
    }

    if (kind === 'post') {
        return 'Posts';
    }

    return 'Ideas';
}

function addedLabel(createdAt: string | null) {
    if (!createdAt) {
        return null;
    }

    return createdAt.slice(0, 10);
}

function scratchBlocks(text: string | null) {
    if (!text) {
        return [];
    }

    return text
        .split(/\n{2,}/)
        .map((block) => block.trim())
        .filter(Boolean);
}

export default function IdeaShow({ idea }: PageProps) {
    const isOpen = idea.status === 'open';
    const isDropped = idea.status === 'dropped';
    const listHref = ideasListHref(idea.kind);
    const added = addedLabel(idea.created_at);
    const why = idea.rationale?.trim() || '';
    const notes = scratchBlocks(idea.body);

    return (
        <>
            <Head title={idea.title} />

            <div className="studio-page studio-home flex min-h-full flex-1 flex-col gap-4">
                <div className="vhead">
                    <Link href={listHref} className="back">
                        ← Ideas
                    </Link>
                    <div className="vhead-t">
                        <span className="no">
                            {idea.human_id || 'Idea · no number yet'}
                        </span>
                        <h2>{idea.title}</h2>
                    </div>
                </div>

                <section className="pane">
                    <div className="pane-head">
                        <span className="k">
                            💡 <b>Ideation</b>
                        </span>
                        <span className={`pill score ${scoreBand(idea.score)}`}>
                            {idea.score !== null ? `${idea.score}/1000` : '-'}
                        </span>
                    </div>
                    <div className="doc-chips">
                        {idea.trend && (
                            <span className="chip">
                                {trendLabel(idea.trend)}
                            </span>
                        )}
                        {added && <span className="chip">added {added}</span>}
                        <span className="chip">{idea.status}</span>
                    </div>
                    {why && (
                        <p className="idea-why">
                            <strong>
                                Why{' '}
                                {idea.score !== null
                                    ? `${idea.score}/1000`
                                    : 'this score'}
                                :
                            </strong>{' '}
                            {why}
                        </p>
                    )}
                    {idea.promoted_to && (
                        <p className="idea-after">
                            Promoted to{' '}
                            <Link
                                href={
                                    idea.promoted_to.kind === 'video'
                                        ? videoShowUrl(
                                              idea.promoted_to.human_id,
                                          )
                                        : postShowUrl(idea.promoted_to.human_id)
                                }
                            >
                                {idea.promoted_to.human_id}:{' '}
                                {idea.promoted_to.title}
                            </Link>
                        </p>
                    )}
                    {isDropped && idea.drop_reason && (
                        <p className="idea-after">
                            Dropped: {idea.drop_reason}
                        </p>
                    )}
                </section>

                <section className="pane">
                    <div className="pane-head">
                        <span className="k">
                            📝 <b>Scratch</b>
                        </span>
                    </div>
                    <div className="scratch">
                        {notes.length === 0 ? (
                            <p className="empty">Nothing scratched down yet.</p>
                        ) : (
                            notes.map((block) => <p key={block}>{block}</p>)
                        )}
                    </div>
                </section>

                {isOpen && (
                    <section className="pane">
                        <div className="pane-head">
                            <span className="k">
                                ↗ <b>Promote</b>
                            </span>
                        </div>
                        <div className="studio-form">
                            <p className="idea-why">
                                Create a draft {idea.kind} shell from this idea.
                            </p>
                            <Form {...IdeasController.promote.form(idea.id)}>
                                {({ processing }) => (
                                    <button
                                        type="submit"
                                        className="advance"
                                        disabled={processing}
                                    >
                                        {idea.kind === 'video'
                                            ? 'Promote to video'
                                            : 'Promote to post'}
                                    </button>
                                )}
                            </Form>
                        </div>
                    </section>
                )}

                <section className="pane">
                    <div className="pane-head">
                        <span className="k">
                            ✎ <b>Edit</b>
                        </span>
                    </div>
                    <Form
                        {...IdeasController.update.form(idea.id)}
                        className="studio-form"
                    >
                        {({ processing, errors }) => (
                            <>
                                <label htmlFor="title">Title</label>
                                <input
                                    id="title"
                                    name="title"
                                    required
                                    defaultValue={idea.title}
                                />
                                <InputError message={errors.title} />

                                <div className="studio-form-row">
                                    <div>
                                        <label htmlFor="score">
                                            Score (0-1000)
                                        </label>
                                        <input
                                            id="score"
                                            type="number"
                                            name="score"
                                            min={0}
                                            max={1000}
                                            defaultValue={idea.score ?? ''}
                                        />
                                        <InputError message={errors.score} />
                                    </div>
                                    <div>
                                        <label htmlFor="trend">Trend</label>
                                        <select
                                            id="trend"
                                            name="trend"
                                            defaultValue={idea.trend ?? ''}
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

                                <label htmlFor="rationale">
                                    Why this score
                                </label>
                                <textarea
                                    id="rationale"
                                    name="rationale"
                                    rows={3}
                                    defaultValue={idea.rationale ?? ''}
                                />
                                <InputError message={errors.rationale} />

                                <label htmlFor="body">Scratch</label>
                                <textarea
                                    id="body"
                                    name="body"
                                    rows={6}
                                    defaultValue={idea.body ?? ''}
                                />
                                <InputError message={errors.body} />

                                <button
                                    type="submit"
                                    className="advance"
                                    disabled={processing}
                                >
                                    Save changes
                                </button>
                            </>
                        )}
                    </Form>
                </section>

                {!isDropped && (
                    <section className="pane">
                        <div className="pane-head">
                            <span className="k">
                                ✕ <b>Drop</b>
                            </span>
                        </div>
                        <Form
                            {...IdeasController.drop.form(idea.id)}
                            className="studio-form"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <label htmlFor="drop_reason">Reason</label>
                                    <textarea
                                        id="drop_reason"
                                        name="drop_reason"
                                        required
                                        rows={2}
                                        placeholder="Why is this idea being dropped?"
                                    />
                                    <InputError message={errors.drop_reason} />
                                    <button
                                        type="submit"
                                        className="advance is-danger"
                                        disabled={processing}
                                    >
                                        Drop idea
                                    </button>
                                </>
                            )}
                        </Form>
                    </section>
                )}
            </div>
        </>
    );
}

IdeaShow.layout = ({ idea }: PageProps) => ({
    breadcrumbs: [
        { title: parentLabel(idea.kind), href: parentIndex(idea.kind) },
        { title: 'Ideas', href: ideasListHref(idea.kind) },
        { title: idea.title, href: '#' },
    ],
});
