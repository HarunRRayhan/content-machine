import { Head, Link } from '@inertiajs/react';
import { useMemo } from 'react';
import { PublishStatusBanner } from '@/components/content/publish-dialog';
import PostCaptionsPanel from '@/components/studio/post-captions-panel';
import PostDraftEditor from '@/components/studio/post-draft-editor';
import PostOverview from '@/components/studio/post-overview';
import type { WorkspaceBucket } from '@/components/studio/workspace-schedule';
import { useStudioTab } from '@/hooks/use-studio-tab';
import type { LangCode } from '@/lib/lang-meta';
import type { HandleDirectory } from '@/lib/post-caption-mock';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index } from '@/routes/posts';

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
    workspaces?: WorkspaceBucket[];
    images: Array<{
        filename: string;
        url: string;
        mime: string;
    }>;
    image_urls: Record<string, string>;
    handles?: HandleDirectory;
    language: string | null;
    slug: string | null;
    template: string | null;
    template_meta: {
        letter: string;
        name: string;
        label: string;
        preview_url: string;
        visual_identity: string;
    } | null;
    status: string;
    publish_state: string;
    publish_error: string | null;
    publish_retryable: boolean;
    publish_progress: {
        state?: string;
        current?: {
            index?: number;
            group_key?: string;
            phase?: string;
        } | null;
    } | null;
    approval_state: string;
    timezone: string;
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
    const hasCaptions = post.captions.some(
        (group) => group.platforms.length > 0,
    );
    // Always allow ?tab=captions so a shared captions link still lands on that
    // panel (empty state) even before captions are uploaded.
    const validTabs = useMemo<readonly TabKey[]>(
        () => ['overview', 'captions'] as const,
        [],
    );
    const [tab, setTab] = useStudioTab(validTabs, 'overview');
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
    const editable =
        ['draft', 'ready'].includes(post.status) &&
        !['queued', 'running'].includes(post.publish_state);

    return (
        <>
            <Head title={post.title} />

            <div className="studio-page flex min-h-full flex-1 flex-col gap-2 p-4">
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
                    contentStatus={post.status}
                />

                <div className="tabbar post-tabbar" role="tablist">
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
                    <PostOverview
                        postId={post.id}
                        title={post.title}
                        status={post.status}
                        platforms={post.platforms}
                        language={post.language}
                        templateMeta={post.template_meta}
                        workspaces={post.workspaces}
                        publishUrl={`/posts/${post.id}/publish`}
                        postsyncerReady={post.postsyncer_ready}
                        publishState={post.publish_state}
                        publishRetryable={post.publish_retryable}
                        publishProgress={post.publish_progress}
                        reconcileUrl={`/posts/${post.id}/reconcile`}
                        approvalState={post.approval_state}
                        timezone={post.timezone}
                        needsConfirmAsk={post.needs_confirm_ask}
                        postsyncer={post.postsyncer}
                        handles={post.handles}
                    />
                )}

                {tab === 'captions' && (
                    <>
                        <PostDraftEditor
                            postId={post.id}
                            title={post.title}
                            body={post.body}
                            groups={post.captions}
                            editable={editable}
                            key={`${post.id}:${post.updated_at ?? ''}`}
                        />
                        {hasCaptions ? (
                            <PostCaptionsPanel
                                groups={post.captions}
                                platforms={post.platforms}
                                imageUrls={post.image_urls}
                                handles={post.handles}
                                defaultLang={defaultLang}
                            />
                        ) : (
                            <p className="empty">
                                No captions on this post yet.
                            </p>
                        )}
                    </>
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
