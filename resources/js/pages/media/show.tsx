import { Form, Head, Link, router } from '@inertiajs/react';
import MediaLibraryController from '@/actions/App/Http/Controllers/Media/MediaLibraryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { gifs, images, videos } from '@/routes/media';

type MediaUsage = {
    label: string;
    href: string | null;
    type: string;
    role?: string | null;
};

type MediaSource = {
    label: string;
    type: string;
};

type MediaDetail = {
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
    original_filename: string | null;
    source: MediaSource | null;
    usage_count: number;
    presentation_asset_key: string | null;
    deletable: boolean;
    usages: MediaUsage[];
};

type PageProps = {
    asset: MediaDetail;
};

function formatBytes(bytes: number): string {
    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function backHref(asset: MediaDetail) {
    if (asset.mime === 'image/gif') {
        return gifs();
    }

    if (asset.kind === 'video') {
        return videos();
    }

    return images();
}

function backLabel(asset: MediaDetail): string {
    if (asset.mime === 'image/gif') {
        return 'GIFs';
    }

    if (asset.kind === 'video') {
        return 'Videos';
    }

    return 'Images';
}

export default function MediaShow({ asset }: PageProps) {
    const canDelete = asset.deletable;

    return (
        <>
            <Head title={asset.title} />

            <div className="flex min-h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={backHref(asset)}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Back to {backLabel(asset)}
                </Link>

                <Heading
                    title={asset.title}
                    description={
                        asset.created_at
                            ? new Date(asset.created_at).toLocaleString()
                            : undefined
                    }
                />

                <div className="flex flex-wrap gap-2">
                    {asset.source && (
                        <Badge variant="outline">{asset.source.label}</Badge>
                    )}
                    <Badge variant="secondary">{asset.mime}</Badge>
                    <Badge variant="outline">{formatBytes(asset.bytes)}</Badge>
                    {asset.width && asset.height && (
                        <Badge variant="outline">
                            {asset.width}×{asset.height}
                        </Badge>
                    )}
                    {asset.presentation_asset_key && (
                        <Badge variant="outline">
                            PA(&apos;{asset.presentation_asset_key}&apos;)
                        </Badge>
                    )}
                </div>

                <div className="max-w-3xl overflow-hidden rounded-lg border bg-muted">
                    {asset.kind === 'video' ? (
                        <video
                            src={asset.preview_url}
                            controls
                            className="max-h-[480px] w-full"
                            playsInline
                        />
                    ) : (
                        <img
                            src={asset.preview_url}
                            alt={asset.title}
                            className="max-h-[480px] w-full object-contain"
                        />
                    )}
                </div>

                <Form
                    {...MediaLibraryController.update.form(asset.public_id)}
                    className="max-w-xl space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="title">Title</Label>
                                <Input
                                    id="title"
                                    name="title"
                                    defaultValue={asset.title}
                                />
                                <InputError message={errors.title} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="description">Description</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    rows={4}
                                    defaultValue={asset.description ?? ''}
                                    placeholder="Describe this file so agents can reuse it."
                                />
                                <InputError message={errors.description} />
                            </div>

                            {asset.original_filename && (
                                <p className="text-sm text-muted-foreground">
                                    Original filename: {asset.original_filename}
                                </p>
                            )}

                            <Button type="submit" disabled={processing}>
                                Save
                            </Button>
                        </>
                    )}
                </Form>

                {asset.usages.length > 0 && (
                    <div className="max-w-xl space-y-2">
                        <h2 className="text-sm font-medium">Used in</h2>
                        <ul className="space-y-1 text-sm">
                            {asset.usages.map((usage, index) => (
                                <li
                                    key={`${usage.type}-${usage.label}-${index}`}
                                >
                                    {usage.href ? (
                                        <Link
                                            href={usage.href}
                                            className="text-primary hover:underline"
                                        >
                                            {usage.label}
                                        </Link>
                                    ) : (
                                        usage.label
                                    )}
                                    {usage.role && (
                                        <span className="text-muted-foreground">
                                            {' '}
                                            ({usage.role})
                                        </span>
                                    )}
                                </li>
                            ))}
                        </ul>
                    </div>
                )}

                <div className="max-w-xl border-t pt-4">
                    <Button
                        type="button"
                        variant="destructive"
                        disabled={!canDelete}
                        onClick={() => {
                            if (
                                confirm(
                                    "Delete this file? This can't be undone.",
                                )
                            ) {
                                router.delete(
                                    MediaLibraryController.destroy.url(
                                        asset.public_id,
                                    ),
                                );
                            }
                        }}
                    >
                        Delete
                    </Button>
                    {!canDelete && (
                        <p className="mt-2 text-sm text-muted-foreground">
                            {asset.source?.type === 'presentation_library'
                                ? 'Presentation library assets are shared deck icons and cannot be deleted.'
                                : 'This file is still attached elsewhere and cannot be deleted yet.'}
                        </p>
                    )}
                </div>
            </div>
        </>
    );
}
