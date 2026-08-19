import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { home } from '@/routes/dashboard';
import { index } from '@/routes/dashboard/scratchpad';

type EntryDetail = {
    id: number;
    public_id: string;
    kind: string;
    status: string;
    source: string;
    title: string | null;
    body: string | null;
    captured_at: string;
};

type PageProps = {
    entry: EntryDetail;
};

export default function ScratchpadShow({ entry }: PageProps) {
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
