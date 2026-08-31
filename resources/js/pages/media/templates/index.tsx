import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, LayoutTemplate } from 'lucide-react';
import Heading from '@/components/heading';
import TemplatePreview from '@/components/media/template-preview';
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
    preview_url: string;
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
                    description="Six reusable post design systems. Each post that used one links back here."
                />

                <div className="grid gap-5 sm:grid-cols-2 xl:grid-cols-3">
                    {templates.map((template) => (
                        <Link
                            key={template.letter}
                            href={showTemplate.url(template.letter)}
                            className="group flex flex-col overflow-hidden rounded-xl border border-border bg-card transition hover:-translate-y-0.5 hover:border-foreground/30 hover:shadow-md"
                        >
                            <TemplatePreview
                                src={template.preview_url}
                                alt={`${template.label} preview`}
                                letter={template.letter}
                                className="aspect-square rounded-t-xl"
                            />
                            <div className="flex flex-1 flex-col p-5">
                                <div className="mb-2 flex items-start justify-between gap-3">
                                    <div>
                                        <div className="font-semibold group-hover:underline">
                                            {template.label}
                                        </div>
                                        <div className="text-sm text-muted-foreground">
                                            {template.name}
                                        </div>
                                    </div>
                                    <ArrowUpRight className="size-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5 group-hover:-translate-y-0.5" />
                                </div>
                                <p className="mb-5 line-clamp-3 flex-1 text-sm text-muted-foreground">
                                    {template.description}
                                </p>
                                <div className="flex items-center justify-between gap-3 text-xs text-muted-foreground">
                                    <span className="inline-flex items-center gap-1">
                                        <LayoutTemplate className="size-3.5" />
                                        {template.visual_identity}
                                    </span>
                                    <span className="shrink-0">
                                        {template.post_count} post
                                        {template.post_count === 1 ? '' : 's'}
                                    </span>
                                </div>
                            </div>
                        </Link>
                    ))}
                </div>
            </div>
        </>
    );
}
