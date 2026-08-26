import { useMemo, useState } from 'react';
import type { CaptionGroup } from '@/components/content/captions-panel';
import { LANG_META  } from '@/lib/lang-meta';
import type {LangCode} from '@/lib/lang-meta';
import {
    PLATFORM_META,
    normalizePlatformKey,
} from '@/lib/platform-meta';

type CaptionGroupWithLang = CaptionGroup & {
    lang?: string | null;
};

type Props = {
    groups: CaptionGroupWithLang[];
    platforms: string[];
    imageUrls: Record<string, string>;
    defaultLang?: LangCode | null;
};

async function copyText(text: string): Promise<void> {
    if (!text) {
        return;
    }

    try {
        await navigator.clipboard.writeText(text);
    } catch {
        // ignore
    }
}

function resolveImages(
    platformImages: string[],
    imageUrls: Record<string, string>,
): Array<{ name: string; url: string | null }> {
    return platformImages.map((name) => ({
        name,
        url: imageUrls[name] ?? imageUrls[name.split('/').pop() ?? ''] ?? null,
    }));
}

function inferLangs(
    groups: CaptionGroupWithLang[],
    defaultLang: LangCode | null,
): LangCode[] {
    const tagged = groups
        .map((group) => group.lang)
        .filter((lang): lang is LangCode => lang === 'bn' || lang === 'en');

    if (tagged.length > 0) {
        return [...new Set(tagged)];
    }

    if (defaultLang) {
        return [defaultLang];
    }

    return groups.length > 0 ? ['bn'] : [];
}

export default function PostCaptionsPanel({
    groups,
    platforms,
    imageUrls,
    defaultLang = null,
}: Props) {
    const langs = inferLangs(groups, defaultLang);
    const multiLang = langs.length > 1;
    const [activeLang, setActiveLang] = useState<LangCode>(langs[0] ?? 'bn');
    const [activeTabByGroup, setActiveTabByGroup] = useState<
        Record<number, number>
    >({});

    const visibleGroups = useMemo(() => {
        if (!multiLang) {
            return groups.map((group, index) => ({ group, index }));
        }

        return groups
            .map((group, index) => ({ group, index }))
            .filter(({ group }) => (group.lang ?? activeLang) === activeLang);
    }, [activeLang, groups, multiLang]);

    if (groups.every((group) => group.platforms.length === 0)) {
        return <p className="empty">No captions yet.</p>;
    }

    return (
        <div className="post-caps space-y-4">
            {multiLang && (
                <div className="cap-langbar" role="tablist">
                    {langs.map((lang) => (
                        <button
                            key={lang}
                            type="button"
                            role="tab"
                            aria-selected={activeLang === lang}
                            onClick={() => setActiveLang(lang)}
                        >
                            <span className="lflag">{LANG_META[lang].flag}</span>
                            {LANG_META[lang].label}
                        </button>
                    ))}
                </div>
            )}

            {visibleGroups.map(({ group, index: groupIndex }) => {
                const platformSet = new Set(
                    platforms.map((name) => name.trim().toLowerCase()),
                );
                const tabs = group.platforms.filter((platform) => {
                    if (platforms.length === 0) {
                        return true;
                    }

                    return platformSet.has(
                        platform.name.trim().toLowerCase(),
                    );
                });
                const list = tabs.length > 0 ? tabs : group.platforms;
                const active =
                    activeTabByGroup[groupIndex] ??
                    (list.length > 0 ? 0 : -1);
                const platform = list[active];
                const tabbed = list.length > 1;

                const header =
                    group.lang && LANG_META[group.lang as LangCode]
                        ? `Captions · ${LANG_META[group.lang as LangCode].flag} ${LANG_META[group.lang as LangCode].label}`
                        : group.part
                          ? `Captions · ${group.part}`
                          : 'Captions';

                return (
                    <section key={`${group.part ?? 'main'}-${groupIndex}`} className="pane">
                        <div className="pane-head">
                            <span className="k">{header}</span>
                        </div>

                        {tabbed && (
                            <div className="cap-tabbar" role="tablist">
                                {list.map((item, platformIndex) => {
                                    const key = normalizePlatformKey(
                                        item.name,
                                    );
                                    const meta = key
                                        ? PLATFORM_META[key]
                                        : null;

                                    return (
                                        <button
                                            key={item.name}
                                            type="button"
                                            role="tab"
                                            aria-selected={
                                                platformIndex === active
                                            }
                                            onClick={() =>
                                                setActiveTabByGroup(
                                                    (prev) => ({
                                                        ...prev,
                                                        [groupIndex]:
                                                            platformIndex,
                                                    }),
                                                )
                                            }
                                        >
                                            {meta && (
                                                <span
                                                    className="tbadge"
                                                    style={{
                                                        background:
                                                            meta.color,
                                                    }}
                                                >
                                                    {meta.badge}
                                                </span>
                                            )}
                                            {item.name}
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        {platform && (
                            <>
                                <div className="mock-wrap">
                                    <div className="mock-note">
                                        Preview · images resolve from Content
                                        Machine storage when uploaded
                                    </div>
                                    <div className="mock">
                                        <div className="mock-head">
                                            <span
                                                className="mock-av"
                                                style={{
                                                    background: '#c23a22',
                                                }}
                                            >
                                                HR
                                            </span>
                                            <div className="mock-id">
                                                <b>Harun R. Rayhan</b>
                                                <small>@iamraycula</small>
                                            </div>
                                            {(() => {
                                                const key =
                                                    normalizePlatformKey(
                                                        platform.name,
                                                    );
                                                const meta = key
                                                    ? PLATFORM_META[key]
                                                    : null;

                                                return meta ? (
                                                    <span
                                                        className="mock-plat-badge"
                                                        style={{
                                                            background:
                                                                meta.color,
                                                        }}
                                                    >
                                                        {meta.badge}
                                                    </span>
                                                ) : null;
                                            })()}
                                        </div>
                                        {platform.title && (
                                            <div className="mock-cap">
                                                <strong>{platform.title}</strong>
                                            </div>
                                        )}
                                        {platform.caption && (
                                            <div className="mock-cap whitespace-pre-wrap">
                                                {platform.caption}
                                            </div>
                                        )}
                                        <div className="mock-imgs">
                                            {resolveImages(
                                                platform.images,
                                                imageUrls,
                                            ).map((image) =>
                                                image.url ? (
                                                    <img
                                                        key={image.name}
                                                        src={image.url}
                                                        alt={image.name}
                                                    />
                                                ) : (
                                                    <div
                                                        key={image.name}
                                                        className="mock-img-missing"
                                                    >
                                                        {image.name}
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="cap">
                                    <div className="cap-h">
                                        <span className="cap-n">
                                            {platform.name}
                                        </span>
                                        <span className="cap-btns">
                                            {platform.title && (
                                                <button
                                                    type="button"
                                                    className="cap-copy"
                                                    onClick={() =>
                                                        copyText(
                                                            platform.title,
                                                        )
                                                    }
                                                >
                                                    ⧉ Title
                                                </button>
                                            )}
                                            {platform.caption && (
                                                <button
                                                    type="button"
                                                    className="cap-copy"
                                                    onClick={() =>
                                                        copyText(
                                                            platform.caption,
                                                        )
                                                    }
                                                >
                                                    ⧉ Caption
                                                </button>
                                            )}
                                        </span>
                                    </div>
                                    {platform.title && (
                                        <div className="cap-t">
                                            {platform.title}
                                        </div>
                                    )}
                                    {platform.caption && (
                                        <div className="cap-b">
                                            {platform.caption}
                                        </div>
                                    )}
                                    {platform.first_comment && (
                                        <>
                                            <span className="cap-l">
                                                First comment
                                            </span>
                                            <div className="cap-b">
                                                {platform.first_comment}
                                            </div>
                                        </>
                                    )}
                                </div>
                            </>
                        )}
                    </section>
                );
            })}
        </div>
    );
}
