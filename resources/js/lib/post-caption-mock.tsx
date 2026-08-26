import { Fragment } from 'react';
import type { ReactNode } from 'react';
import type { CaptionPlatform } from '@/components/content/captions-panel';
import type { LangCode } from '@/lib/lang-meta';
import type { PlatformKey } from '@/lib/platform-meta';
import { PLATFORM_META, normalizePlatformKey } from '@/lib/platform-meta';
import { resolvePostImages } from '@/lib/resolve-post-images';

export type HandlePreview = {
    handle: string;
    name: string;
};

export type HandleDirectory = {
    bn: Record<string, HandlePreview>;
    en: Record<string, HandlePreview>;
};

const DISPLAY_NAME = 'Harun R. Rayhan';

const HANDLE_AT: ReadonlySet<PlatformKey> = new Set([
    'twitter',
    'threads',
    'bluesky',
]);

const REACTIONS: Partial<Record<PlatformKey, string>> = {
    facebook: '👍 Like   💬 Comment   ↪ Share',
    instagram: '♡ Like   💬 Comment   ✈ Send   🔖 Save',
    tiktok: '♡ Like   💬 Comment   ↪ Share',
    youtube: '👍 Like   💬 Comment   ↪ Share',
    twitter: '💬 Reply   🔁 Repost   ♡ Like   ↗ Share',
    threads: '♡ Like   💬 Reply   🔁 Repost   ↗ Share',
    bluesky: '💬 Reply   🔁 Repost   ♡ Like',
    linkedin: '👍 Like   💬 Comment   🔁 Repost   ➤ Send',
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
    if (!handles || platform === null) {
        return '';
    }

    return handles[lang]?.[platform]?.handle ?? '';
}

function threadItems(thread: unknown[]): string[] {
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

    if (resolved.length === 0) {
        return null;
    }

    const multi = !compact && resolved.length > 1;

    return (
        <div className={multi ? 'mock-imgs mock-imgs-multi' : 'mock-imgs'}>
            {resolved.map((image) =>
                image.url ? (
                    <img key={image.name} src={image.url} alt={image.name} />
                ) : (
                    <div key={image.name} className="mock-img-missing">
                        {image.name}
                    </div>
                ),
            )}
        </div>
    );
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
    const noHashtags = key === 'instagram';
    const bangla = lang === 'bn';
    const title = platform.title.trim();
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
    const reactions = key ? REACTIONS[key] : undefined;

    return (
        <div className="mock-wrap">
            <div className="mock-note">Preview</div>
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
                {title ? (
                    <div className={bangla ? 'mock-cap is-bn' : 'mock-cap'}>
                        <strong>{title}</strong>
                    </div>
                ) : null}
                {caption ? (
                    <MockCaption
                        text={caption}
                        noHashtags={noHashtags}
                        bangla={bangla}
                    />
                ) : title ? null : (
                    <div className="mock-cap">
                        <p className="empty-inline">No caption yet.</p>
                    </div>
                )}
                {headImages.length > 0 ? (
                    <MockImages images={headImages} imageUrls={imageUrls} />
                ) : (
                    <p className="empty mock-imgs-empty">
                        No image on {meta?.labels.en ?? platform.name}.
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
            </div>
        </div>
    );
}
