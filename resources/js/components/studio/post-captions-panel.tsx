import { useMemo, useState } from 'react';
import type { CaptionGroup } from '@/components/content/captions-panel';
import { LANG_META, orderLangs } from '@/lib/lang-meta';
import type { LangCode } from '@/lib/lang-meta';
import {
    PLATFORM_META,
    normalizePlatformKey,
    orderPlatformsByLang,
    platformLabel,
} from '@/lib/platform-meta';
import { PostCaptionMock } from '@/lib/post-caption-mock';
import type { HandleDirectory } from '@/lib/post-caption-mock';

type CaptionGroupWithLang = CaptionGroup & {
    lang?: string | null;
};

type Props = {
    groups: CaptionGroupWithLang[];
    platforms: string[];
    imageUrls: Record<string, string>;
    handles?: HandleDirectory;
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

function inferLangs(
    groups: CaptionGroupWithLang[],
    defaultLang: LangCode | null,
): LangCode[] {
    const tagged = groups
        .map((group) => group.lang)
        .filter((lang): lang is LangCode => lang === 'bn' || lang === 'en');

    if (tagged.length > 0) {
        return orderLangs(tagged);
    }

    if (defaultLang) {
        return [defaultLang];
    }

    return groups.length > 0 ? ['en'] : [];
}

export default function PostCaptionsPanel({
    groups,
    platforms,
    imageUrls,
    handles,
    defaultLang = null,
}: Props) {
    const langs = inferLangs(groups, defaultLang);
    const multiLang = langs.length > 1;
    const [activeLang, setActiveLang] = useState<LangCode>(langs[0] ?? 'en');
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
                            className={lang === 'bn' ? 'is-bn' : undefined}
                            onClick={() => setActiveLang(lang)}
                        >
                            <span className="lflag">
                                {LANG_META[lang].flag}
                            </span>
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

                    return platformSet.has(platform.name.trim().toLowerCase());
                });
                const list = orderPlatformsByLang(
                    tabs.length > 0 ? tabs : group.platforms,
                    group.lang ?? activeLang,
                );
                const active =
                    activeTabByGroup[groupIndex] ?? (list.length > 0 ? 0 : -1);
                const platform = list[active];
                const tabbed = list.length > 1;
                const viewLang: LangCode =
                    group.lang === 'bn' || group.lang === 'en'
                        ? group.lang
                        : activeLang;
                const banglaUi = viewLang === 'bn';

                const header =
                    group.lang && LANG_META[group.lang as LangCode]
                        ? `Captions · ${LANG_META[group.lang as LangCode].flag} ${LANG_META[group.lang as LangCode].label}`
                        : group.part
                          ? `Captions · ${group.part}`
                          : 'Captions';

                return (
                    <section
                        key={`${group.part ?? 'main'}-${groupIndex}`}
                        className="pane"
                    >
                        <div className="pane-head">
                            <span className={banglaUi ? 'k is-bn' : 'k'}>
                                {header}
                            </span>
                        </div>

                        {tabbed && (
                            <div className="cap-tabbar" role="tablist">
                                {list.map((item, platformIndex) => {
                                    const key = normalizePlatformKey(item.name);
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
                                            className={
                                                banglaUi ? 'is-bn' : undefined
                                            }
                                            onClick={() =>
                                                setActiveTabByGroup((prev) => ({
                                                    ...prev,
                                                    [groupIndex]: platformIndex,
                                                }))
                                            }
                                        >
                                            {meta && (
                                                <span
                                                    className="tbadge"
                                                    style={{
                                                        background: meta.color,
                                                    }}
                                                >
                                                    {meta.badge}
                                                </span>
                                            )}
                                            {platformLabel(item.name, viewLang)}
                                        </button>
                                    );
                                })}
                            </div>
                        )}

                        {platform && (
                            <>
                                <PostCaptionMock
                                    platform={platform}
                                    lang={viewLang}
                                    handles={handles}
                                    imageUrls={imageUrls}
                                />

                                <div className="cap">
                                    <div className="cap-h">
                                        <span
                                            className={
                                                banglaUi
                                                    ? 'cap-n is-bn'
                                                    : 'cap-n'
                                            }
                                        >
                                            {platformLabel(
                                                platform.name,
                                                viewLang,
                                            )}
                                        </span>
                                        <span className="cap-btns">
                                            {platform.title && (
                                                <button
                                                    type="button"
                                                    className="cap-copy"
                                                    onClick={() =>
                                                        copyText(platform.title)
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
                                            {platform.first_comment && (
                                                <button
                                                    type="button"
                                                    className="cap-copy"
                                                    onClick={() =>
                                                        copyText(
                                                            platform.first_comment,
                                                        )
                                                    }
                                                >
                                                    ⧉ Comment
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
