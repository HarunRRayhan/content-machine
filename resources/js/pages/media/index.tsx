import { Form, Head, Link } from '@inertiajs/react';
import { Upload } from 'lucide-react';
import { useState } from 'react';
import MediaLibraryController from '@/actions/App/Http/Controllers/Media/MediaLibraryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { show } from '@/routes/media';

type MediaSource = {
    label: string;
    type: string;
};

type MediaSummary = {
    public_id: string;
    title: string;
    description: string | null;
    kind: string;
    mime: string;
    bytes: number;
    width: number | null;
    height: number | null;
    created_at: string | null;
    preview_url: string;
    source: MediaSource | null;
    usage_count: number;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

type PaginatedItems = {
    data: MediaSummary[];
    links: PaginationLink[];
    total: number;
};

type PageProps = {
    tab: 'images' | 'videos' | 'gifs';
    tabLabel: string;
    items: PaginatedItems;
};

function paginationLabel(label: string): string {
    return label.replace('&laquo;', '«').replace('&raquo;', '»');
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function MediaPreview({ item }: { item: MediaSummary }) {
    if (item.kind === 'video') {
        return (
            <video
                src={item.preview_url}
                className="size-full object-cover"
                muted
                playsInline
                preload="metadata"
            />
        );
    }

    return (
        <img
            src={item.preview_url}
            alt={item.title}
            className="size-full object-cover"
            loading="lazy"
        />
    );
}

export default function MediaIndex({ tab, tabLabel, items }: PageProps) {
    const [uploadOpen, setUploadOpen] = useState(false);

    return (
        <>
            <Head title={`Media · ${tabLabel}`} />

            <div className="flex min-h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={home()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Dashboard
                </Link>

                <div className="flex flex-wrap items-start justify-between gap-4">
                    <Heading
                        title={tabLabel}
                        description={`${items.total} file${items.total === 1 ? '' : 's'} in this workspace`}
                    />

                    <Button
                        type="button"
                        size="sm"
                        onClick={() => setUploadOpen((open) => !open)}
                    >
                        <Upload className="size-4" />
                        Upload {tabLabel.toLowerCase()}
                    </Button>
                </div>

                {uploadOpen && (
                    <Form
                        {...MediaLibraryController.store.form()}
                        encType="multipart/form-data"
                        className="max-w-xl space-y-4 rounded-lg border p-4"
                        onSuccess={() => setUploadOpen(false)}
                    >
                        {({ processing, errors }) => (
                            <>
                                <input type="hidden" name="tab" value={tab} />

                                <div className="grid gap-2">
                                    <Label htmlFor="file">File</Label>
                                    <Input
                                        id="file"
                                        name="file"
                                        type="file"
                                        accept={
                                            tab === 'videos'
                                                ? 'video/mp4,video/webm,video/quicktime,.mp4,.webm,.mov'
                                                : tab === 'gifs'
                                                  ? 'image/gif,.gif'
                                                  : 'image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp'
                                        }
                                        required
                                    />
                                    <InputError message={errors.file} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="title">Title</Label>
                                    <Input
                                        id="title"
                                        name="title"
                                        placeholder="Optional display name"
                                    />
                                    <InputError message={errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">
                                        Description
                                    </Label>
                                    <Textarea
                                        id="description"
                                        name="description"
                                        rows={3}
                                        placeholder="What is this file? Agents use this to pick the right asset later."
                                    />
                                    <InputError message={errors.description} />
                                </div>

                                <div className="flex gap-2">
                                    <Button type="submit" disabled={processing}>
                                        Upload
                                    </Button>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setUploadOpen(false)}
                                    >
                                        Cancel
                                    </Button>
                                </div>
                            </>
                        )}
                    </Form>
                )}

                {items.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No {tabLabel.toLowerCase()} yet. Upload one to get
                        started.
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                        {items.data.map((item) => (
                            <Link
                                key={item.public_id}
                                href={show(item.public_id)}
                                className="group overflow-hidden rounded-lg border transition hover:border-primary/40"
                            >
                                <div className="aspect-square overflow-hidden bg-muted">
                                    <MediaPreview item={item} />
                                </div>
                                <div className="space-y-2 p-3">
                                    <p className="truncate font-medium">
                                        {item.title}
                                    </p>
                                    <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        {item.source && (
                                            <Badge variant="outline">
                                                {item.source.label}
                                            </Badge>
                                        )}
                                        {item.usage_count > 0 && (
                                            <span>
                                                Used {item.usage_count}×
                                            </span>
                                        )}
                                        <span>{formatBytes(item.bytes)}</span>
                                    </div>
                                </div>
                            </Link>
                        ))}
                    </div>
                )}

                {items.links.length > 3 && (
                    <nav className="flex flex-wrap gap-2">
                        {items.links.map((link) =>
                            link.url ? (
                                <Link
                                    key={link.label}
                                    href={link.url}
                                    className={
                                        link.active
                                            ? 'rounded-md bg-primary px-3 py-1 text-sm text-primary-foreground'
                                            : 'rounded-md px-3 py-1 text-sm text-muted-foreground hover:bg-muted'
                                    }
                                    preserveScroll
                                >
                                    {paginationLabel(link.label)}
                                </Link>
                            ) : (
                                <span
                                    key={link.label}
                                    className="rounded-md px-3 py-1 text-sm text-muted-foreground/50"
                                >
                                    {paginationLabel(link.label)}
                                </span>
                            ),
                        )}
                    </nav>
                )}
            </div>
        </>
    );
}
