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

type WorkspaceKey = LangCode | 'unk';

type WorkspaceBucket = {
    key: WorkspaceKey;
    groups: PostsyncerGroup[];
    platforms: string[];
};

const WORKSPACE_ORDER: WorkspaceKey[] = ['bn', 'en', 'unk'];

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

function workspaceTitle(key: WorkspaceKey): string {
    if (key === 'unk') {
        return 'Unlabeled workspace';
    }

    const meta = LANG_META[key];

    return `${meta.flag} ${meta.label} workspace`;
}

function previewLang(key: WorkspaceKey): LangCode {
    return key === 'en' ? 'en' : 'bn';
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
    if (buckets.length === 0) {
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
            {buckets.map((bucket) => (
                <div key={bucket.key} className="schedule-log-ws">
                    <div className="workspace-plat-h">
                        {workspaceTitle(bucket.key)}
                    </div>
                    <ul>
                        {bucket.groups.map((group, index) => {
                            const when = formatWhen(
                                group.published_at ??
                                    group.scheduled_at ??
                                    null,
                            );
                            const id = group.post_id;

                            return (
                                <li key={`${String(id ?? 'group')}-${index}`}>
                                    <div className="schedule-log-meta">
                                        <span className="schedule-log-when">
                                            {when
                                                ? `${when} ${timezone}`
                                                : 'No time yet'}
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
                                        {(group.platforms ?? []).map(
                                            (platform) => (
                                                <PlatformMark
                                                    key={platform}
                                                    platform={platform}
                                                    lang={previewLang(
                                                        bucket.key,
                                                    )}
                                                    handles={handles}
                                                    showHandle={false}
                                                />
                                            ),
                                        )}
                                    </span>
                                </li>
                            );
                        })}
                    </ul>
                </div>
            ))}
        </div>
    );
}
