import { Head, Link } from '@inertiajs/react';
import { LayoutTemplate } from 'lucide-react';
import Heading from '@/components/heading';
import { home } from '@/routes/dashboard';
import { show as showTemplate } from '@/routes/media/templates';

type TemplateSummary = {
    letter: string;
    slug: string;
    name: string;
    description: string;
    visual_identity: string;
    proven_on_human_id: string | null;
    proven_on_label: string | null;
    label: string;
    post_count: number;
};

type PageProps = {
    templates: TemplateSummary[];
};

export default function MediaTemplatesIndex({ templates }: PageProps) {
    return (
        <>
            <Head title="Media · Templates" />

            <div className="flex min-h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={home()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Dashboard
                </Link>

                <Heading
                    title="Templates"
                    description="Post design templates A–F. Each post that used one links back here."
                />

                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    {templates.map((template) => (
                        <Link
                            key={template.letter}
                            href={showTemplate.url(template.letter)}
                            className="group rounded-xl border border-border bg-card p-5 transition hover:border-foreground/30"
                        >
                            <div className="mb-3 flex items-center gap-3">
                                <span className="flex size-10 items-center justify-center rounded-lg bg-muted text-lg font-bold">
                                    {template.letter}
                                </span>
                                <div>
                                    <div className="font-semibold group-hover:underline">
                                        {template.label}
                                    </div>
                                    <div className="text-sm text-muted-foreground">
                                        {template.name}
                                    </div>
                                </div>
                            </div>
                            <p className="mb-4 line-clamp-3 text-sm text-muted-foreground">
                                {template.description}
                            </p>
                            <div className="flex items-center justify-between text-xs text-muted-foreground">
                                <span className="inline-flex items-center gap-1">
                                    <LayoutTemplate className="size-3.5" />
                                    {template.visual_identity}
                                </span>
                                <span>
                                    {template.post_count} post
                                    {template.post_count === 1 ? '' : 's'}
                                </span>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}
