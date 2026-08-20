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
import { index, update } from '@/routes/dashboard/posts';

type PostDetail = {
    id: number;
    human_id: string;
    title: string;
    body: string | null;
    status: string;
    idea_id: number | null;
    created_at: string | null;
};

type PageProps = {
    post: PostDetail;
};

export default function PostShow({ post }: PageProps) {
    return (
        <>
            <Head title={post.title} />

            <div className="flex h-full flex-1 flex-col gap-6 rounded-xl p-4">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    &larr; Back to Posts
                </Link>

                <Heading title={post.title} description={post.human_id} />

                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline">{post.human_id}</Badge>
                    <Badge variant="secondary">{post.status}</Badge>
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

                <div className="max-w-2xl space-y-4 rounded-lg border p-4">
                    <Heading variant="small" title="Edit post" />

                    <Form {...update.form(post.id)} className="space-y-4">
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
                                    <InputError message={errors.title} />
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

PostShow.layout = {
    breadcrumbs: [
        { title: 'Dashboard', href: home() },
        { title: 'Posts', href: index() },
    ],
};
