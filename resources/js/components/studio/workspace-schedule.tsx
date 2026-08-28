import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type CSSProperties,
} from 'react';
import { createPortal } from 'react-dom';
import { LANG_META } from '@/lib/lang-meta';
import type { LangCode } from '@/lib/lang-meta';
import {
    PLATFORM_META,
    normalizePlatformKey,
    platformLabel,
} from '@/lib/platform-meta';
import type { PlatformKey } from '@/lib/platform-meta';
import { formatPlatformHandle } from '@/lib/post-caption-mock';
import type { HandleDirectory } from '@/lib/studio-workspaces';
import { STUDIO_HANDLES } from '@/lib/studio-workspaces';

export type PostsyncerGroup = {
    post_id?: string | number;
    status?: string;
    scheduled_at?: string | null;
    published_at?: string | null;
    platforms?: string[];
    language?: string;
    lang?: string;
};

export type WorkspaceKey = LangCode | 'unk';

export type WorkspaceBucket = {
    key: WorkspaceKey;
    groups?: PostsyncerGroup[];
    platforms: string[];
};

const WORKSPACE_ORDER: WorkspaceKey[] = ['en', 'bn', 'unk'];

export function groupLanguage(group: PostsyncerGroup): WorkspaceKey {
    const raw = (group.lang ?? group.language ?? '').trim().toLowerCase();

    if (
        raw === 'bn' ||
        raw === 'bangla' ||
        raw === 'bengali' ||
        raw === 'বাংলা'
    ) {
        return 'bn';
    }

    if (raw === 'en' || raw === 'english' || raw === 'ইংরেজি') {
        return 'en';
    }

    return 'unk';
}

export function bucketsFromGroups(
    groups: PostsyncerGroup[],
): WorkspaceBucket[] {
    const byKey: Record<WorkspaceKey, PostsyncerGroup[]> = {
        bn: [],
        en: [],
        unk: [],
    };

    for (const group of groups) {
        byKey[groupLanguage(group)].push(group);
    }

    return WORKSPACE_ORDER.filter((key) => byKey[key].length > 0).map(
        (key) => ({
            key,
            groups: byKey[key],
            platforms: uniquePlatforms(byKey[key]),
        }),
    );
}

function uniquePlatforms(groups: PostsyncerGroup[]): string[] {
    const seen = new Set<string>();
    const out: string[] = [];

    for (const group of groups) {
        for (const platform of group.platforms ?? []) {
            const key = platform.trim().toLowerCase();

            if (key === '' || seen.has(key)) {
                continue;
            }

            seen.add(key);
            out.push(platform);
        }
    }

    return out;
}

export function workspaceTitle(key: WorkspaceKey): string {
    if (key === 'unk') {
        return 'Unlabeled workspace';
    }

    const meta = LANG_META[key];

    return `${meta.flag} ${meta.label} workspace`;
}

export function workspaceShortName(key: WorkspaceKey): string {
    if (key === 'unk') {
        return 'Workspace';
    }

    const meta = LANG_META[key];

    return `${meta.flag} ${meta.label}`;
}

export function previewLang(key: WorkspaceKey): LangCode {
    return key === 'en' ? 'en' : 'bn';
}

export function defaultWorkspaceTab(buckets: WorkspaceBucket[]): WorkspaceKey {
    const english = buckets.find((bucket) => bucket.key === 'en');

    if (english) {
        return 'en';
    }

    return buckets[0]?.key ?? 'en';
}

/** Draft posts expose workspace chips without PostSyncer groups yet. */
function withGroups(bucket: WorkspaceBucket): WorkspaceBucket {
    return {
        ...bucket,
        groups: bucket.groups ?? [],
    };
}

export function workspacesForIndex(
    groups: PostsyncerGroup[],
    language: string | null | undefined,
    platforms: string[],
): WorkspaceBucket[] {
    const fromGroups = bucketsFromGroups(groups).filter(
        (bucket) => bucket.key !== 'unk' || bucket.platforms.length > 0,
    );

    if (fromGroups.length > 0) {
        return fromGroups;
    }

    if (language === 'both' && platforms.length > 0) {
        return [
            { key: 'bn', groups: [], platforms },
            { key: 'en', groups: [], platforms },
        ];
    }

    if (platforms.length === 0 && !language) {
        return [];
    }

    return [
        {
            key: language === 'en' ? 'en' : 'bn',
            groups: [],
            platforms,
        },
    ];
}

function WorkspaceIndexChip({ workspace }: { workspace: WorkspaceBucket }) {
    const lang = previewLang(workspace.key);
    const hasPlatforms = workspace.platforms.length > 0;
    const anchorRef = useRef<HTMLSpanElement>(null);
    const menuRef = useRef<HTMLDivElement>(null);
    const closeTimerRef = useRef<number | null>(null);
    const [open, setOpen] = useState(false);
    const [menuStyle, setMenuStyle] = useState<CSSProperties>({});

    const clearCloseTimer = () => {
        if (closeTimerRef.current !== null) {
            window.clearTimeout(closeTimerRef.current);
            closeTimerRef.current = null;
        }
    };

    const scheduleClose = () => {
        clearCloseTimer();
        closeTimerRef.current = window.setTimeout(() => setOpen(false), 120);
    };

    const openMenu = () => {
        clearCloseTimer();
        setOpen(true);
    };

    const updatePosition = useCallback(() => {
        const anchor = anchorRef.current;
        const menu = menuRef.current;

        if (!anchor || !menu) {
            return;
        }

        const rect = anchor.getBoundingClientRect();
        const menuHeight = menu.offsetHeight;
        const menuWidth = menu.offsetWidth;
        const gap = 6;
        const padding = 8;

        let top = rect.bottom + gap;

        if (top + menuHeight > window.innerHeight - padding) {
            top = Math.max(padding, rect.top - menuHeight - gap);
        }

        let left = rect.left;

        if (left + menuWidth > window.innerWidth - padding) {
            left = window.innerWidth - menuWidth - padding;
        }

        left = Math.max(padding, left);

        setMenuStyle({
            position: 'fixed',
            top,
            left,
            zIndex: 1000,
        });
    }, []);

    useLayoutEffect(() => {
        if (!open) {
            return;
        }

        updatePosition();
    }, [open, updatePosition, workspace.platforms]);

    useEffect(() => {
        if (!open) {
            return;
        }

        const onScrollOrResize = () => updatePosition();

        window.addEventListener('scroll', onScrollOrResize, true);
        window.addEventListener('resize', onScrollOrResize);

        return () => {
            window.removeEventListener('scroll', onScrollOrResize, true);
            window.removeEventListener('resize', onScrollOrResize);
        };
    }, [open, updatePosition]);

    useEffect(() => () => clearCloseTimer(), []);

    const portalRoot =
        typeof document !== 'undefined'
            ? (document.querySelector('.studio-page') ?? document.body)
            : null;

    const menu =
        hasPlatforms && open && portalRoot
            ? createPortal(
                  <div
                      ref={menuRef}
                      className="ws-chip-menu ws-chip-menu--portal"
                      role="menu"
                      style={menuStyle}
                      onMouseEnter={openMenu}
                      onMouseLeave={scheduleClose}
                      onClick={(event) => event.stopPropagation()}
                      onMouseDown={(event) => event.stopPropagation()}
                  >
                      {workspace.platforms.map((platform) => (
                          <PlatformMark
                              key={platform}
                              platform={platform}
                              lang={lang}
                              showHandle={false}
                          />
                      ))}
                  </div>,
                  portalRoot,
              )
            : null;

    return (
        <>
            <span
                ref={anchorRef}
                className={`ws-chip${hasPlatforms ? ' ws-chip--menu' : ''}`}
                tabIndex={hasPlatforms ? 0 : undefined}
                onMouseEnter={openMenu}
                onMouseLeave={scheduleClose}
                onFocus={openMenu}
                onBlur={(event) => {
                    const next = event.relatedTarget as Node | null;

                    if (
                        next &&
                        (anchorRef.current?.contains(next) ||
                            menuRef.current?.contains(next))
                    ) {
                        return;
                    }

                    scheduleClose();
                }}
                onClick={(event) => event.stopPropagation()}
                onMouseDown={(event) => event.stopPropagation()}
            >
                <span className="ws-chip-name">
                    {workspaceShortName(workspace.key)}
                </span>
            </span>
            {menu}
        </>
    );
}

export function IndexWorkspaceChips({
    workspaces: presetWorkspaces,
    groups,
    language,
    platforms,
}: {
    workspaces?: WorkspaceBucket[];
    groups: PostsyncerGroup[];
    language: string | null | undefined;
    platforms: string[];
}) {
    const resolved =
        presetWorkspaces && presetWorkspaces.length > 0
            ? presetWorkspaces
            : workspacesForIndex(groups, language, platforms);

    if (resolved.length === 0) {
        return <>—</>;
    }

    return (
        <div className="ws-chips">
            {resolved.map((workspace) => (
                <WorkspaceIndexChip key={workspace.key} workspace={workspace} />
            ))}
        </div>
    );
}

function handleFor(
    handles: HandleDirectory | undefined,
    lang: LangCode,
    platform: PlatformKey,
): string {
    const live = handles?.[lang]?.[platform]?.handle ?? '';

    if (live !== '') {
        return live;
    }

    return STUDIO_HANDLES[lang]?.[platform]?.handle ?? '';
}

export function PlatformMark({
    platform,
    lang,
    handles,
    showHandle = true,
}: {
    platform: string;
    lang: LangCode;
    handles?: HandleDirectory;
    showHandle?: boolean;
}) {
    const key = normalizePlatformKey(platform);
    const meta = key ? PLATFORM_META[key] : null;
    const name = platformLabel(platform, lang);
    const handle = key
        ? formatPlatformHandle(key, handleFor(handles, lang, key))
        : '';

    return (
        <span className="plat-mark">
            {meta ? (
                <span
                    className="platform-badge"
                    style={{ background: meta.color }}
                    title={name}
                >
                    {meta.badge}
                </span>
            ) : null}
            <span className="plat-mark-name">{name}</span>
            {showHandle && handle !== '' ? (
                <span className="plat-mark-handle">{handle}</span>
            ) : null}
        </span>
    );
}

export function PlatformChipRow({
    platforms,
    lang,
    handles,
}: {
    platforms: string[];
    lang: LangCode;
    handles?: HandleDirectory;
}) {
    if (platforms.length === 0) {
        return null;
    }

    return (
        <div className="workspace-plats">
            <div className="workspace-plat-list">
                {platforms.map((platform) => (
                    <PlatformMark
                        key={platform}
                        platform={platform}
                        lang={lang}
                        handles={handles}
                    />
                ))}
            </div>
        </div>
    );
}

export function WorkspacePlatformChips({
    buckets,
    handles,
}: {
    buckets: WorkspaceBucket[];
    handles?: HandleDirectory;
}) {
    if (buckets.length === 0) {
        return null;
    }

    return (
        <div className="workspace-plats">
            {buckets.map((bucket) => (
                <div key={bucket.key} className="workspace-plat">
                    <div className="workspace-plat-h">
                        {workspaceTitle(bucket.key)}
                    </div>
                    <div className="workspace-plat-list">
                        {bucket.platforms.map((platform) => (
                            <PlatformMark
                                key={`${bucket.key}-${platform}`}
                                platform={platform}
                                lang={previewLang(bucket.key)}
                                handles={handles}
                            />
                        ))}
                    </div>
                </div>
            ))}
        </div>
    );
}

function ScheduleGroupList({
    bucket,
    formatWhen,
    timezone,
    handles,
}: {
    bucket: WorkspaceBucket;
    formatWhen: (value: string | null | undefined) => string | null;
    timezone: string;
    handles?: HandleDirectory;
}) {
    const groups = bucket.groups ?? [];

    if (groups.length === 0) {
        return (
            <p className="schedule-log-empty">
                Nothing scheduled or published in this workspace yet.
            </p>
        );
    }

    return (
        <ul>
            {groups.map((group, index) => {
                const when = formatWhen(
                    group.published_at ?? group.scheduled_at ?? null,
                );
                const id = group.post_id;

                return (
                    <li key={`${String(id ?? 'group')}-${index}`}>
                        <div className="schedule-log-meta">
                            <span className="schedule-log-when">
                                {when ? `${when} ${timezone}` : 'No time yet'}
                            </span>
                            {group.status ? (
                                <span className="schedule-log-status">
                                    {group.status}
                                </span>
                            ) : null}
                            {id !== undefined && id !== '' ? (
                                <span className="schedule-log-id">
                                    #{String(id)}
                                </span>
                            ) : null}
                        </div>
                        <span className="schedule-log-plats">
                            {(group.platforms ?? []).map((platform) => (
                                <PlatformMark
                                    key={platform}
                                    platform={platform}
                                    lang={previewLang(bucket.key)}
                                    handles={handles}
                                    showHandle={false}
                                />
                            ))}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

export function WorkspaceScheduleLog({
    buckets,
    formatWhen,
    timezone,
    empty,
    handles,
    heading = 'Scheduled posts',
}: {
    buckets: WorkspaceBucket[];
    formatWhen: (value: string | null | undefined) => string | null;
    timezone: string;
    empty?: string;
    handles?: HandleDirectory;
    heading?: string;
}) {
    const normalized = buckets.map(withGroups);
    const unlabeled = normalized.find((bucket) => bucket.key === 'unk');
    const visibleTabs: WorkspaceBucket[] =
        normalized.length === 0
            ? []
            : [
                  normalized.find((bucket) => bucket.key === 'en') ?? {
                      key: 'en',
                      groups: [],
                      platforms: [],
                  },
                  normalized.find((bucket) => bucket.key === 'bn') ?? {
                      key: 'bn',
                      groups: [],
                      platforms: [],
                  },
              ];
    const [active, setActive] = useState<WorkspaceKey>(() =>
        defaultWorkspaceTab(
            visibleTabs.filter((bucket) => (bucket.groups ?? []).length > 0),
        ),
    );
    const activeBucket =
        visibleTabs.find((bucket) => bucket.key === active) ?? visibleTabs[0];

    if (visibleTabs.length === 0) {
        if (!empty) {
            return null;
        }

        return (
            <div className="schedule-log">
                <div className="schedule-log-h">{heading}</div>
                <p className="schedule-log-empty">{empty}</p>
            </div>
        );
    }

    return (
        <div className="schedule-log">
            <div className="schedule-log-h">{heading}</div>
            {visibleTabs.length > 1 ? (
                <div className="cap-langbar schedule-ws-tabs" role="tablist">
                    {visibleTabs.map((bucket) => (
                        <button
                            key={bucket.key}
                            type="button"
                            role="tab"
                            aria-selected={bucket.key === activeBucket?.key}
                            className={
                                bucket.key === 'bn' ? 'is-bn' : undefined
                            }
                            onClick={() => setActive(bucket.key)}
                        >
                            {workspaceTitle(bucket.key)}
                        </button>
                    ))}
                </div>
            ) : (
                <div className="workspace-plat-h">
                    {workspaceTitle(visibleTabs[0].key)}
                </div>
            )}
            {activeBucket ? (
                <ScheduleGroupList
                    bucket={activeBucket}
                    formatWhen={formatWhen}
                    timezone={timezone}
                    handles={handles}
                />
            ) : null}
            {unlabeled && (unlabeled.groups ?? []).length > 0 ? (
                <div className="schedule-log-ws">
                    <div className="workspace-plat-h">
                        {workspaceTitle('unk')}
                    </div>
                    <ScheduleGroupList
                        bucket={unlabeled}
                        formatWhen={formatWhen}
                        timezone={timezone}
                        handles={handles}
                    />
                </div>
            ) : null}
        </div>
    );
}
