import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import PublishDialog, {
    PublishStatusBanner,
} from '@/components/content/publish-dialog';
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

type TabKey = 'overview' | 'captions';

export default function PostShow({ post }: PageProps) {
    const [tab, setTab] = useState<TabKey>('overview');
    const hasCaptions = post.captions.some(
        (group) => group.platforms.length > 0,
    );
    const hasEnglishCaptions = post.captions.some(
        (group) => group.lang === 'en',
    );
    const defaultLang: LangCode | null = hasEnglishCaptions
        ? 'en'
        : post.language === 'en'
          ? 'en'
          : post.language === 'bn'
            ? 'bn'
            : null;

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

                <PublishStatusBanner
                    publishState={post.publish_state}
                    publishError={post.publish_error}
                />

                <div className="tabbar" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'overview'}
                        onClick={() => setTab('overview')}
                    >
                        📋 Overview
                    </button>
                    <button
                        type="button"
                        role="tab"
                        aria-selected={tab === 'captions'}
                        onClick={() => setTab('captions')}
                    >
                        📣 Captions
                    </button>
                </div>

                {tab === 'overview' && (
                    <div className="space-y-4">
                        <PostOverview
                            postId={post.id}
                            title={post.title}
                            status={post.status}
                            platforms={post.platforms}
                        />
                        <section className="pane max-w-3xl">
                            <div className="pane-head">
                                <span className="k">📤 Publish</span>
                            </div>
                            <div className="p-5">
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
                        </section>
                    </div>
                )}

                {tab === 'captions' &&
                    (hasCaptions ? (
                        <PostCaptionsPanel
                            groups={post.captions}
                            platforms={post.platforms}
                            imageUrls={post.image_urls}
                            defaultLang={defaultLang}
                        />
                    ) : (
                        <p className="empty">No captions on this post yet.</p>
                    ))}
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
