import { Fragment, useState } from 'react';
import type { ReactNode } from 'react';
import type { CaptionPlatform } from '@/components/content/captions-panel';
import ImageLightbox from '@/components/studio/image-lightbox';
import type { LangCode } from '@/lib/lang-meta';
import type { PlatformKey } from '@/lib/platform-meta';
import { PLATFORM_META, normalizePlatformKey } from '@/lib/platform-meta';
import {
    resolveLinkedinCarouselPdf,
    resolvePostImages,
} from '@/lib/resolve-post-images';
import type { HandleDirectory } from '@/lib/studio-workspaces';
import { STUDIO_HANDLES } from '@/lib/studio-workspaces';

export type { HandleDirectory, HandlePreview } from '@/lib/studio-workspaces';

const DISPLAY_NAME = 'Harun R. Rayhan';

const HANDLE_AT: ReadonlySet<PlatformKey> = new Set([
    'twitter',
    'threads',
    'bluesky',
]);

const PLATFORM_MOCKUP: Record<
    PlatformKey,
    { reactions: string; tip: string; noHashtags?: boolean; label: string }
> = {
    facebook: {
        label: 'Facebook',
        reactions: '👍 Like   💬 Comment   ↪ Share',
        tip: 'Practical caption safety margin ~2,000 chars (Facebook rejects the whole post past 2,500). Square or 1.91:1 image.',
    },
    instagram: {
        label: 'Instagram',
        reactions: '♡ Like   💬 Comment   ✈ Send   🔖 Save',
        noHashtags: true,
        tip: "No hashtags on this project's Instagram posts. Square (1:1) or 4:5 image.",
    },
    tiktok: {
        label: 'TikTok',
        reactions: '♡ Like   💬 Comment   ↪ Share',
        tip: 'Static images only, no GIFs. Caption cap 4,000 chars.',
    },
    youtube: {
        label: 'YouTube',
        reactions: '👍 Like   💬 Comment   ↪ Share',
        tip: 'Image posts are not supported on YouTube: video-only platform.',
    },
    twitter: {
        label: 'Twitter / X',
        reactions: '💬 Reply   🔁 Repost   ♡ Like   ↗ Share',
        tip: '25,000-char cap (X Premium). Hashtags live inline in the text, no dedicated field. Native GIF support.',
    },
    threads: {
        label: 'Threads',
        reactions: '♡ Like   💬 Reply   🔁 Repost   ↗ Share',
        tip: '500-char cap. GIF support added April 2026.',
    },
    bluesky: {
        label: 'Bluesky',
        reactions: '💬 Reply   🔁 Repost   ♡ Like',
        tip: '300-char cap. No native GIF upload (Tenor-embed only). A raw GIF needs MP4 conversion first.',
    },
    linkedin: {
        label: 'LinkedIn',
        reactions: '👍 Like   💬 Comment   🔁 Repost   ➤ Send',
        tip: 'English only. 2+ images become a real document-post carousel (one PDF, swipeable), assembled at publish time, not a plain multi-image post.',
    },
};

const CAPLIM: Partial<Record<PlatformKey, number>> = {
    instagram: 2200,
    facebook: 2000,
    tiktok: 4000,
    youtube: 300,
    twitter: 25000,
    threads: 500,
    bluesky: 300,
};

export function formatPlatformHandle(
    platform: PlatformKey | null,
    handle: string,
): string {
    const bare = handle.replace(/^@+/, '').trim();

    if (!bare || platform === null) {
        return '';
    }

    return HANDLE_AT.has(platform) ? `@${bare}` : bare;
}

export function resolvePreviewHandle(
    handles: HandleDirectory | undefined,
    lang: LangCode,
    platform: PlatformKey | null,
): string {
    if (platform === null) {
        return '';
    }

    const live = handles?.[lang]?.[platform]?.handle ?? '';

    if (live !== '') {
        return live;
    }

    return STUDIO_HANDLES[lang]?.[platform]?.handle ?? '';
}

export function threadItems(thread: unknown[]): string[] {
    return thread
        .map((item) => (typeof item === 'string' ? item.trim() : ''))
        .filter((item) => item !== '');
}

function highlightHashtags(text: string): ReactNode[] {
    const parts: ReactNode[] = [];
    const re = /(^|[\s(])#([\wঀ-৿]+)/g;
    let last = 0;
    let match: RegExpExecArray | null;
    let key = 0;

    while ((match = re.exec(text)) !== null) {
        if (match.index > last) {
            parts.push(text.slice(last, match.index));
        }

        parts.push(match[1]);
        parts.push(
            <span key={`tag-${key++}`} className="mock-tag">
                #{match[2]}
            </span>,
        );
        last = match.index + match[0].length;
    }

    if (last < text.length) {
        parts.push(text.slice(last));
    }

    return parts;
}

function captionParagraphs(text: string, noHashtags: boolean): ReactNode {
    return text.split(/\n{2,}/).map((para, i) => (
        <p key={i}>
            {para.split('\n').map((line, j) => (
                <Fragment key={j}>
                    {j > 0 && <br />}
                    {noHashtags ? line : highlightHashtags(line)}
                </Fragment>
            ))}
        </p>
    ));
}

function MockCaption({
    text,
    noHashtags,
    bangla,
}: {
    text: string;
    noHashtags: boolean;
    bangla: boolean;
}) {
    return (
        <div className={bangla ? 'mock-cap is-bn' : 'mock-cap'}>
            {captionParagraphs(text, noHashtags)}
        </div>
    );
}

function MockImages({
    images,
    imageUrls,
    compact = false,
}: {
    images: string[];
    imageUrls: Record<string, string>;
    compact?: boolean;
}) {
    const resolved = resolvePostImages(images, imageUrls);
    const viewable = resolved.flatMap((image) =>
        image.url ? [{ name: image.name, url: image.url }] : [],
    );
    const [openIndex, setOpenIndex] = useState<number | null>(null);

    if (resolved.length === 0) {
        return null;
    }

    const multi = !compact && resolved.length > 1;

    return (
        <>
            <div className={multi ? 'mock-imgs mock-imgs-multi' : 'mock-imgs'}>
                {resolved.map((image) =>
                    image.url ? (
                        <button
                            key={image.name}
                            type="button"
                            className="mock-img-open"
                            onClick={() =>
                                setOpenIndex(
                                    viewable.findIndex(
                                        (item) => item.name === image.name,
                                    ),
                                )
                            }
                        >
                            <img src={image.url} alt={image.name} />
                        </button>
                    ) : (
                        <div key={image.name} className="mock-img-missing">
                            {image.name}
                        </div>
                    ),
                )}
            </div>
            <ImageLightbox
                images={viewable}
                startIndex={openIndex}
                onClose={() => setOpenIndex(null)}
            />
        </>
    );
}

function MockLinkedInMedia({
    images,
    imageUrls,
}: {
    images: string[];
    imageUrls: Record<string, string>;
}) {
    const pdf = resolveLinkedinCarouselPdf(images, imageUrls);

    if (images.length >= 2 && pdf) {
        return (
            <>
                <div className="mock-pdf">
                    <embed src={pdf.url} type="application/pdf" />
                </div>
                <div className="mock-pdf-open">
                    <a href={pdf.url} target="_blank" rel="noopener">
                        Open PDF in a new tab ↗
                    </a>
                </div>
                <div className="mock-note">
                    LinkedIn ships these {images.length} slides as one
                    document-post carousel (PDF).
                </div>
            </>
        );
    }

    if (images.length >= 2) {
        return (
            <>
                <MockImages images={images} imageUrls={imageUrls} />
                <div className="mock-note">
                    Carousel PDF not staged yet. Publish will assemble one from
                    these {images.length} slides.
                </div>
            </>
        );
    }

    if (images.length > 0) {
        return <MockImages images={images} imageUrls={imageUrls} />;
    }

    return <p className="empty mock-imgs-empty">No image on LinkedIn.</p>;
}

export function PostCaptionMock({
    platform,
    lang,
    handles,
    imageUrls,
}: {
    platform: CaptionPlatform;
    lang: LangCode;
    handles?: HandleDirectory;
    imageUrls: Record<string, string>;
}) {
    const key = normalizePlatformKey(platform.name);
    const meta = key ? PLATFORM_META[key] : null;
    const color = meta?.color ?? '#c23a22';
    const handle = formatPlatformHandle(
        key,
        resolvePreviewHandle(handles, lang, key),
    );
    const cfg = key ? PLATFORM_MOCKUP[key] : null;
    const noHashtags = cfg?.noHashtags === true;
    const bangla = lang === 'bn';
    const caption = platform.caption.trim();
    const firstComment = platform.first_comment.trim();
    const thread = threadItems(platform.thread);
    const threadTweets =
        thread.length > 0
            ? thread
            : key === 'twitter' && firstComment
              ? [firstComment]
              : [];
    const showFirstComment = threadTweets.length === 0 && firstComment !== '';
    const images = platform.images;
    const perTweetImages = thread.length > 0 && images.length > 1;
    const headImages = perTweetImages ? images.slice(0, 1) : images;
    const reactions = cfg?.reactions;
    const capMax = key ? CAPLIM[key] : undefined;
    const over = capMax !== undefined && caption.length > capMax;
    const label = cfg?.label ?? platform.name;

    return (
        <div className="mock-wrap">
            <div className={key ? `mock mock-${key}` : 'mock'}>
                <div className="mock-head">
                    <span className="mock-av" style={{ background: color }}>
                        HR
                    </span>
                    <div className="mock-id">
                        <b>{DISPLAY_NAME}</b>
                        {handle ? <small>{handle}</small> : null}
                    </div>
                    {meta ? (
                        <span
                            className="mock-plat-badge"
                            style={{ background: meta.color }}
                        >
                            {meta.badge}
                        </span>
                    ) : null}
                </div>
                {caption ? (
                    <MockCaption
                        text={caption}
                        noHashtags={noHashtags}
                        bangla={bangla}
                    />
                ) : (
                    <div className="mock-cap">
                        <p className="empty-inline">
                            No caption resolved for {label} yet.
                        </p>
                    </div>
                )}
                {key === 'linkedin' ? (
                    <MockLinkedInMedia
                        images={headImages}
                        imageUrls={imageUrls}
                    />
                ) : headImages.length > 0 ? (
                    <MockImages images={headImages} imageUrls={imageUrls} />
                ) : (
                    <p className="empty mock-imgs-empty">
                        No image on {label}.
                    </p>
                )}
                {reactions ? <div className="mock-bar">{reactions}</div> : null}
                {threadTweets.length > 0 ? (
                    <div className="mock-thread">
                        <div className="mock-thread-tag">
                            🧵 Connected thread, {threadTweets.length + 1}{' '}
                            tweets
                        </div>
                        {threadTweets.map((tweet, index) => (
                            <div
                                className="mock-thread-item"
                                key={`tweet-${index}`}
                            >
                                <div className="mock-thread-line" />
                                <span
                                    className="mock-av"
                                    style={{ background: color }}
                                >
                                    HR
                                </span>
                                <div className="mock-thread-body">
                                    <div className="mock-id">
                                        <b>{DISPLAY_NAME}</b>
                                        {handle ? (
                                            <small>{handle}</small>
                                        ) : null}
                                    </div>
                                    <MockCaption
                                        text={tweet}
                                        noHashtags={noHashtags}
                                        bangla={bangla}
                                    />
                                    {perTweetImages && images[index + 1] ? (
                                        <MockImages
                                            images={[images[index + 1]]}
                                            imageUrls={imageUrls}
                                            compact
                                        />
                                    ) : null}
                                    <div className="mock-meta mock-meta-thread">
                                        <span
                                            className={
                                                capMax !== undefined &&
                                                tweet.length > capMax
                                                    ? 'over'
                                                    : undefined
                                            }
                                        >
                                            {tweet.length}
                                            {capMax
                                                ? ` / ${capMax} chars`
                                                : ' chars'}
                                            {capMax !== undefined &&
                                            tweet.length > capMax
                                                ? ' · over the limit'
                                                : ''}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : null}
                {showFirstComment ? (
                    <div className="mock-fc">
                        <span
                            className="mock-av mock-av-sm"
                            style={{ background: color }}
                        >
                            HR
                        </span>
                        <div
                            className={bangla ? 'mock-fc-b is-bn' : 'mock-fc-b'}
                        >
                            <b>{DISPLAY_NAME}</b>
                            {captionParagraphs(firstComment, noHashtags)}
                        </div>
                    </div>
                ) : null}
                <div className="mock-meta">
                    <span className={over ? 'over' : undefined}>
                        {caption.length}
                        {capMax ? ` / ${capMax} chars` : ' chars'}
                        {over ? ' · over the limit' : ''}
                    </span>
                    {cfg?.tip ? (
                        <span className="mock-tip">{cfg.tip}</span>
                    ) : null}
                </div>
            </div>
        </div>
    );
}

export function firstCommentLabel(
    platformName: string,
    thread: string[],
): string {
    return normalizePlatformKey(platformName) === 'twitter' &&
        thread.length === 0
        ? '↳ Tweet 2 (thread)'
        : '↳ First comment';
}

export function postCount(text: string): string {
    const trimmed = text.trim();
    const words = trimmed ? trimmed.split(/\s+/).length : 0;

    return `${trimmed.length} chars · ${words} words`;
}
