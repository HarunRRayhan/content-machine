import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import CaptionsPanel, {
    type CaptionGroup,
} from '@/components/content/captions-panel';
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
import { index, update } from '@/routes/dashboard/videos';

type VideoDetail = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    body: string | null;
    script_markdown: string | null;
    captions: CaptionGroup[];
    deck_manifest: Record<string, unknown> | null;
    has_deck: boolean;
    language: string | null;
    slug: string | null;
    status: string;
    idea_id: number | null;
    created_at: string | null;
    updated_at: string | null;
};

type PageProps = {
    video: VideoDetail;
};

export default function VideoShow({ video }: PageProps) {
    const hasScript = Boolean(video.script_markdown?.trim());
    const hasCaptions = video.captions.some(
        (group) => group.platforms.length > 0,
    );
    const defaultTab = hasScript
        ? 'script'
        : hasCaptions
          ? 'captions'
          : 'overview';
    const [tab, setTab] = useState(defaultTab);

    return (
        <>
            <Head title={video.title} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; All videos
                </Link>

                <div className="space-y-2">
                    <p className="text-sm text-muted-foreground">
                        Video #{video.number}
                    </p>
                    <Heading
                        title={video.title}
                        description={video.human_id}
                    />
                </div>

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{video.human_id}</Badge>
                    <Badge variant="secondary">{video.status}</Badge>
                    {video.language && (
                        <Badge variant="outline">{video.language}</Badge>
                    )}
                    {video.slug && (
                        <Badge variant="outline">{video.slug}</Badge>
                    )}
                    {hasScript && <Badge variant="outline">script</Badge>}
                    {hasCaptions && <Badge variant="outline">captions</Badge>}
                    {video.has_deck && (
                        <Badge variant="outline">presentation</Badge>
                    )}
                </div>

                {video.idea_id && (
                    <p className="text-sm text-muted-foreground">
                        Promoted from{' '}
                        <Link
                            href={showIdea.url(video.idea_id)}
                            className="underline"
                        >
                            idea #{video.idea_id}
                        </Link>
                    </p>
                )}

                <Tabs value={tab} onValueChange={setTab} className="space-y-4">
                    <TabsList className="flex h-auto flex-wrap gap-1">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="script">Script</TabsTrigger>
                        <TabsTrigger value="captions">Captions</TabsTrigger>
                        <TabsTrigger value="presentation">
                            Presentation
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="overview" className="space-y-4">
                        <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                            <Heading variant="small" title="Edit video" />

                            <Form
                                {...update.form(video.id)}
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
                                                defaultValue={video.title}
                                            />
                                            <InputError
                                                message={errors.title}
                                            />
                                        </div>

                                        <div className="grid gap-2">
                                            <Label htmlFor="body">
                                                Notes / body
                                            </Label>
                                            <Textarea
                                                id="body"
                                                name="body"
                                                rows={8}
                                                defaultValue={video.body ?? ''}
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
                    </TabsContent>

                    <TabsContent value="script">
                        {hasScript ? (
                            <pre className="max-w-4xl whitespace-pre-wrap rounded-lg border bg-muted/30 p-4 text-sm leading-relaxed">
                                {video.script_markdown}
                            </pre>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No script stored for this video yet.
                            </p>
                        )}
                    </TabsContent>

                    <TabsContent value="captions">
                        <CaptionsPanel groups={video.captions} />
                    </TabsContent>

                    <TabsContent value="presentation" className="space-y-3">
                        {video.has_deck ? (
                            <>
                                <p className="text-sm text-muted-foreground">
                                    Presentation package is stored on this
                                    video. Fullscreen player opens in a
                                    dedicated view.
                                </p>
                                <Button asChild>
                                    <a href={`/dashboard/videos/${video.id}/presentation`}>
                                        Open fullscreen presentation
                                    </a>
                                </Button>
                                {video.deck_manifest && (
                                    <pre className="max-w-3xl overflow-auto rounded-lg border bg-muted/30 p-3 text-xs">
                                        {JSON.stringify(
                                            video.deck_manifest,
                                            null,
                                            2,
                                        )}
                                    </pre>
                                )}
                            </>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                No presentation deck imported for this video
                                yet. Decks from Script Studio (BV-46 onward)
                                will show up here after the media import.
                            </p>
                        )}
                    </TabsContent>
                </Tabs>
            </div>
        </>
    );
}

VideoShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Videos', href: index() },
    ],
};
