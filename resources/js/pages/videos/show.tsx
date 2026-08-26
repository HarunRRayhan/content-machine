import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import type { CaptionGroup } from '@/components/content/captions-panel';
import { PublishStatusBanner } from '@/components/content/publish-dialog';
import PresentationEmbed from '@/components/studio/presentation-embed';
import ScriptPanel from '@/components/studio/script-panel';
import VideoCaptionsPanel from '@/components/studio/video-captions-panel';
import VideoOverview from '@/components/studio/video-overview';
import { home } from '@/routes/dashboard';
import { show as showIdea } from '@/routes/dashboard/ideas';
import { index } from '@/routes/videos';

type ScriptBlock = {
    lang: string;
    body: string;
};

type TalkingPoint = {
    label: string;
    text: string;
};

type VideoDetail = {
    id: number;
    human_id: string;
    number: number;
    title: string;
    body: string | null;
    script_markdown: string | null;
    parsed: {
        lang: string;
        length: string;
        parts: number;
        points: TalkingPoint[];
        scripts: ScriptBlock[];
        facts: string[];
        sources: string;
        legal: string[];
    };
    captions: CaptionGroup[];
    deck_manifest: Record<string, unknown> | null;
    has_deck: boolean;
    video_drive_url: string | null;
    cover_drive_url: string | null;
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
    video: VideoDetail;
};

type TabKey = 'overview' | 'script' | 'captions' | 'facts' | 'presentation';

export default function VideoShow({ video }: PageProps) {
    const hasScript = video.parsed.scripts.length > 0;
    const hasCaptions = video.captions.some(
        (group) => group.platforms.length > 0,
    );
    const hasDeck = video.has_deck;
    const hasFacts =
        video.parsed.facts.length > 0 ||
        video.parsed.sources !== '' ||
        video.parsed.legal.length > 0;

    const validTabs: TabKey[] = ['overview'];

    if (!hasDeck && hasScript) {
        validTabs.push('script');
    }

    if (hasFacts) {
        validTabs.push('facts');
    }

    if (hasCaptions) {
        validTabs.push('captions');
    }

    if (hasDeck) {
        validTabs.push('presentation');
    }

    const [tab, setTab] = useState<TabKey>('overview');
    const activeTab = validTabs.includes(tab) ? tab : 'overview';

    return (
        <>
            <Head title={video.title} />

            <div className="studio-page flex h-full flex-1 flex-col gap-2 p-4">
                <div className="vhead">
                    <Link href={index()} className="back">
                        ← All videos
                    </Link>
                    <div className="vhead-t">
                        <span className="no">Video #{video.number}</span>
                        <h2>{video.title}</h2>
                    </div>
                </div>

                {video.idea_id && (
                    <p className="text-sm text-[var(--ink-soft)]">
                        Promoted from{' '}
                        <Link
                            href={showIdea.url(video.idea_id)}
                            className="underline"
                        >
                            idea #{video.idea_id}
                        </Link>
                    </p>
                )}

                <PublishStatusBanner
                    publishState={video.publish_state}
                    publishError={video.publish_error}
                />

                <div className="tabbar" role="tablist">
                    <button
                        type="button"
                        role="tab"
                        aria-selected={activeTab === 'overview'}
                        onClick={() => setTab('overview')}
                    >
                        📋 Overview
                    </button>
                    {!hasDeck && hasScript && (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'script'}
                            onClick={() => setTab('script')}
                        >
                            📄 Script
                        </button>
                    )}
                    {hasCaptions && (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'captions'}
                            onClick={() => setTab('captions')}
                        >
                            📣 Captions
                        </button>
                    )}
                    {hasFacts && (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'facts'}
                            onClick={() => setTab('facts')}
                        >
                            🔍 Fact-check
                        </button>
                    )}
                    {hasDeck && (
                        <button
                            type="button"
                            role="tab"
                            aria-selected={activeTab === 'presentation'}
                            onClick={() => setTab('presentation')}
                        >
                            🎬 Presentation
                        </button>
                    )}
                </div>

                {activeTab === 'overview' && (
                    <VideoOverview
                        videoId={video.id}
                        title={video.title}
                        status={video.status}
                        lang={video.parsed.lang}
                        length={video.parsed.length}
                        points={video.parsed.points}
                        storageKey={video.human_id}
                        videoDriveUrl={video.video_drive_url}
                        coverDriveUrl={video.cover_drive_url}
                        publishUrl={`/videos/${video.id}/publish`}
                        postsyncerReady={video.postsyncer_ready}
                        publishState={video.publish_state}
                        needsConfirmAsk={video.needs_confirm_ask}
                        postsyncer={video.postsyncer}
                    />
                )}

                {activeTab === 'script' && !hasDeck && (
                    <ScriptPanel
                        scripts={video.parsed.scripts}
                        videoNumber={video.number}
                        storageKey={video.human_id}
                    />
                )}

                {activeTab === 'captions' && (
                    <VideoCaptionsPanel groups={video.captions} />
                )}

                {activeTab === 'facts' && (
                    <section className="pane max-w-3xl">
                        <div className="pane-head">
                            <span className="k">Fact-check</span>
                        </div>
                        <div className="p-5 text-sm leading-relaxed text-[var(--ink-soft)]">
                            {video.parsed.facts.length > 0 && (
                                <ul className="mb-4 list-disc space-y-2 pl-5">
                                    {video.parsed.facts.map((fact) => (
                                        <li key={fact}>{fact}</li>
                                    ))}
                                </ul>
                            )}
                            {video.parsed.sources && (
                                <p className="mb-4">
                                    <strong>Sources:</strong>{' '}
                                    {video.parsed.sources}
                                </p>
                            )}
                            {video.parsed.legal.length > 0 && (
                                <ul className="list-disc space-y-2 pl-5">
                                    {video.parsed.legal.map((note) => (
                                        <li key={note}>{note}</li>
                                    ))}
                                </ul>
                            )}
                            {!hasFacts && (
                                <p className="empty">No fact-check notes.</p>
                            )}
                        </div>
                    </section>
                )}

                {activeTab === 'presentation' && hasDeck && (
                    <PresentationEmbed
                        title={video.title}
                        src={`/videos/${video.id}/presentation?embed=1`}
                    />
                )}
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
