import { Head, Link } from '@inertiajs/react';
import PostCaptionsPanel from '@/components/studio/post-captions-panel';
import PostOverview from '@/components/studio/post-overview';
import type { LangCode } from '@/lib/lang-meta';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index } from '@/routes/dashboard/posts';

type PostDetail = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    body: string | null;
    captions: Array<{
        part: string | null;
        lang?: string | null;
        platforms: Array<{
            name: string;
            title: string;
            caption: string;
            first_comment: string;
            images: string[];
            thread: unknown[];
        }>;
    }>;
    platforms: string[];
    image_urls: Record<string, string>;
    language: string | null;
    slug: string | null;
    status: string;
    idea_id: number | null;
    created_at: string | null;
    updated_at: string | null;
};

type PageProps = {
    post: PostDetail;
};

export default function PostShow({ post }: PageProps) {
    const hasCaptions = post.captions.some(
        (group) => group.platforms.length > 0,
    );
    const defaultLang: LangCode | null =
        post.language === 'en' ? 'en' : post.language === 'bn' ? 'bn' : null;

    return (
        <>
            <Head title={post.title} />

            <div className="studio-page flex h-full flex-1 flex-col gap-2 p-4">
                <div className="vhead">
                    <Link href={index()} className="back">
                        ← All posts
                    </Link>
                    <div className="vhead-t">
                        <span className="no">P-{post.number}</span>
                        <h2>{post.title}</h2>
                    </div>
                </div>

                {post.idea_id && (
                    <p className="text-sm text-[var(--ink-soft)]">
                        Promoted from{' '}
                        <Link
                            href={showIdea.url(post.idea_id)}
                            className="underline"
                        >
                            idea #{post.idea_id}
                        </Link>
                    </p>
                )}

                <PostOverview
                    postId={post.id}
                    title={post.title}
                    status={post.status}
                    platforms={post.platforms}
                />

                {hasCaptions ? (
                    <PostCaptionsPanel
                        groups={post.captions}
                        platforms={post.platforms}
                        imageUrls={post.image_urls}
                        defaultLang={defaultLang}
                    />
                ) : (
                    <p className="empty">No captions on this post yet.</p>
                )}
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
