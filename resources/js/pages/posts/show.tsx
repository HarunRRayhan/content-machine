import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CaptionsPanel from '@/components/content/captions-panel';
import type { CaptionGroup } from '@/components/content/captions-panel';
import PublishDialog from '@/components/content/publish-dialog';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index, update } from '@/routes/dashboard/posts';

type PostImage = {
    id: number;
    role: string;
    platform: string | null;
    filename: string | null;
    url: string | null;
    mime: string | null;
};

type PostDetail = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    body: string | null;
    captions: CaptionGroup[];
    platforms: string[];
    images: PostImage[];
    caption_image_names: string[];
    image_drive_urls: string[];
    language: string | null;
    slug: string | null;
    status: string;
    publish_state: string;
    publish_error: string | null;
    postsyncer: Record<string, unknown> | null;
    postsyncer_ready: boolean;
    needs_confirm_ask: boolean;
    idea_id: number | null;
    created_at: string | null;
    updated_at: string | null;
};

type PageProps = {
    post: PostDetail;
};

function driveUrlsToText(urls: string[]): string {
    return urls.join('\n');
}

export default function PostShow({ post }: PageProps) {
    const hasCaptions = post.captions.some(
        (group) => group.platforms.length > 0,
    );
    const hasImages =
        post.images.length > 0 || post.caption_image_names.length > 0;
    const defaultTab = hasCaptions ? 'captions' : 'overview';
    const [tab, setTab] = useState(defaultTab);

    const publishDisabled =
        !post.postsyncer_ready ||
        ['queued', 'running'].includes(post.publish_state);
    const publishDisabledReason = !post.postsyncer_ready
        ? 'Configure PostSyncer in Settings before scheduling or publishing.'
        : ['queued', 'running'].includes(post.publish_state)
          ? 'A publish job is already queued or running.'
          : null;

    return (
        <>
            <Head title={post.title} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; All posts
                </Link>

                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        Post #{post.number}
                    </p>
                    <Heading title={post.title} description={post.human_id} />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{post.human_id}</Badge>
                    <Badge variant="secondary">{post.status}</Badge>
                    {post.publish_state !== 'idle' && (
                        <Badge variant="outline">{post.publish_state}</Badge>
                    )}
                    {post.language && (
                        <Badge variant="outline">{post.language}</Badge>
                    )}
                    {post.platforms.map((platform) => (
                        <Badge key={platform} variant="outline">
                            {platform}
                        </Badge>
                    ))}
                </div>

                {post.idea_id && (
                    <p className="text-sm text-muted-foreground">
                        Promoted from{' '}
                        <Link
                            href={showIdea.url(post.idea_id)}
                            className="underline"
                        >
                            idea #{post.idea_id}
                        </Link>
                    </p>
                )}

                <Tabs value={tab} onValueChange={setTab} className="space-y-4">
                    <TabsList className="flex h-auto flex-wrap gap-1">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="captions">Captions</TabsTrigger>
                        <TabsTrigger value="images">Images</TabsTrigger>
                        <TabsTrigger value="body">Body</TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="space-y-4">
                        <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                            <Heading variant="small" title="Edit post" />

                            <Form
                                {...update.form(post.id)}
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
                                                defaultValue={post.title}
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="body">Body</Label>
                                            <Textarea
                                                id="body"
                                                name="body"
                                                rows={10}
                                                defaultValue={post.body ?? ''}
                                            />
                                            <InputError message={errors.body} />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="image_drive_urls">
                                                Image Drive URLs
                                            </Label>
                                            <Textarea
                                                id="image_drive_urls"
                                                name="image_drive_urls"
                                                rows={4}
                                                placeholder="One Google Drive URL per line"
                                                defaultValue={driveUrlsToText(
                                                    post.image_drive_urls,
                                                )}
                                            />
                                            <p className="text-xs text-muted-foreground">
                                                Used when images are not
                                                uploaded as attachments.
                                            </p>
                                            <InputError
                                                message={
                                                    errors.image_drive_urls
                                                }
                                            />
                                        </div>

                                        <Button disabled={processing}>
                                            Save changes
                                        </Button>
                                    </>
                                )}
                            </Form>
                        </div>

                        <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                            <Heading
                                variant="small"
                                title="Publish"
                                description="Schedule or publish this post through PostSyncer."
                            />

                            <PublishDialog
                                disabled={publishDisabled}
                                disabledReason={publishDisabledReason}
                                publishState={post.publish_state}
                                publishError={post.publish_error}
                                publishUrl={`/dashboard/posts/${post.id}/publish`}
                                entityLabel="post"
                                needsConfirmAsk={post.needs_confirm_ask}
                            />
                        </div>
                    </TabsContent>

                    <TabsContent value="captions">
                        <CaptionsPanel groups={post.captions} />
                    </TabsContent>

                    <TabsContent value="images" className="space-y-4">
                        {post.images.length > 0 ? (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                                {post.images.map((image) => (
                                    <figure
                                        key={image.id}
                                        className="overflow-hidden rounded-lg border"
                                    >
                                        {image.url ? (
                                            <img
                                                src={image.url}
                                                alt={
                                                    image.filename ??
                                                    'Post image'
                                                }
                                                className="aspect-square w-full object-cover"
                                            />
                                        ) : (
                                            <div className="flex aspect-square items-center justify-center bg-muted text-sm text-muted-foreground">
                                                No preview URL
                                            </div>
                                        )}
                                        <figcaption className="space-y-1 p-2 text-xs text-muted-foreground">
                                            <p>{image.filename}</p>
                                            <p>
                                                {image.role}
                                                {image.platform
                                                    ? ` · ${image.platform}`
                                                    : ''}
                                            </p>
                                        </figcaption>
                                    </figure>
                                ))}
                            </div>
                        ) : hasImages ? (
                            <div className="space-y-2">
                                <p className="text-sm text-muted-foreground">
                                    Image files are referenced in captions but
                                    not uploaded to Content Machine storage yet:
                                </p>
                                <ul className="list-inside list-disc text-sm">
                                    {post.caption_image_names.map((name) => (
                                        <li key={name}>{name}</li>
                                    ))}
                                </ul>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No images on this post yet.
                            </p>
                        )}
                    </TabsContent>

                    <TabsContent value="body">
                        {post.body ? (
                            <pre className="max-w-4xl rounded-lg border bg-muted/30 p-4 text-sm leading-relaxed whitespace-pre-wrap">
                                {post.body}
                            </pre>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No body stored for this post.
                            </p>
                        )}
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

PostShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Posts', href: index() },
    ],
};
