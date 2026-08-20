import { Form, Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index, update } from '@/routes/dashboard/videos';

type VideoDetail = {
    id: number;
    human_id: string;
    title: string;
    body: string | null;
    status: string;
    idea_id: number | null;
    created_at: string | null;
};

type PageProps = {
    video: VideoDetail;
};

export default function VideoShow({ video }: PageProps) {
    return (
        <>
            <Head title={video.title} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Back to Videos
                </Link>

                <Heading title={video.title} description={video.human_id} />

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{video.human_id}</Badge>
                    <Badge variant="secondary">{video.status}</Badge>
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

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Heading variant="small" title="Edit video" />

                    <Form {...update.form(video.id)} className="space-y-4">
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
                                    <InputError message={errors.title} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="body">Body</Label>
                                    <Textarea
                                        id="body"
                                        name="body"
                                        rows={10}
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
