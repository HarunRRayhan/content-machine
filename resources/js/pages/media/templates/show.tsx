import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { templates as templatesIndex } from '@/routes/media';
import { show as showPost } from '@/routes/posts';

type TemplateDetail = {
    letter: string;
    slug: string;
    name: string;
    description: string;
    visual_identity: string;
    proven_on_human_id: string | null;
    proven_on_label: string | null;
    label: string;
};

type TemplatePost = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    status: string;
    updated_at: string | null;
};

type PageProps = {
    template: TemplateDetail;
    posts: TemplatePost[];
};

export default function MediaTemplateShow({ template, posts }: PageProps) {
    return (
        <>
            <Head title={`Media · ${template.label}`} />

            <div className="flex min-h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={templatesIndex()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; All templates
                </Link>

                <div className="flex flex-wrap items-start gap-4">
                    <span className="flex size-14 items-center justify-center rounded-xl bg-muted text-2xl font-bold">
                        {template.letter}
                    </span>
                    <Heading
                        title={template.label}
                        description={template.name}
                    />
                </div>

                <div className="max-w-3xl space-y-3 rounded-xl border border-border p-5">
                    <p className="text-sm leading-relaxed">
                        {template.description}
                    </p>
                    <div className="flex flex-wrap gap-2 text-sm text-muted-foreground">
                        <Badge variant="secondary">
                            {template.visual_identity}
                        </Badge>
                        <span className="font-mono text-xs">
                            {template.slug}
                        </span>
                    </div>
                    {template.proven_on_human_id && (
                        <p className="text-sm text-muted-foreground">
                            Proven on {template.proven_on_human_id}
                            {template.proven_on_label
                                ? ` (${template.proven_on_label})`
                                : ''}
                        </p>
                    )}
                </div>

                <div>
                    <h3 className="mb-3 text-sm font-semibold tracking-wide text-muted-foreground uppercase">
                        Posts using this template ({posts.length})
                    </h3>
                    {posts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            No posts in this workspace are tagged with Template{' '}
                            {template.letter} yet.
                        </p>
                    ) : (
                        <ul className="divide-y divide-border rounded-xl border border-border">
                            {posts.map((post) => (
                                <li key={post.id}>
                                    <Link
                                        href={showPost.url(post.id)}
                                        className="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-muted/40"
                                    >
                                        <div>
                                            <span className="mr-2 font-mono text-sm text-muted-foreground">
                                                {post.human_id}
                                            </span>
                                            <span className="font-medium">
                                                {post.title}
                                            </span>
                                        </div>
                                        <Badge variant="outline">
                                            {post.status}
                                        </Badge>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </div>
        </>
    );
}
