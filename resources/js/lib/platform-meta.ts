export type PlatformKey =
    | 'facebook'
    | 'instagram'
    | 'tiktok'
    | 'youtube'
    | 'twitter'
    | 'threads'
    | 'bluesky'
    | 'linkedin';

export const PLATFORM_META: Record<
    PlatformKey,
    { badge: string; color: string }
> = {
    facebook: { badge: 'f', color: '#1877F2' },
    instagram: { badge: 'IG', color: '#E4405F' },
    tiktok: { badge: 'TT', color: '#000000' },
    youtube: { badge: 'YT', color: '#FF0000' },
    twitter: { badge: 'X', color: '#000000' },
    threads: { badge: '@', color: '#000000' },
    bluesky: { badge: 'BS', color: '#1185FE' },
    linkedin: { badge: 'in', color: '#0A66C2' },
};

export function normalizePlatformKey(name: string): PlatformKey | null {
    const key = name.trim().toLowerCase();
    if (key in PLATFORM_META) {
        return key as PlatformKey;
    }

    return null;
}

export const POST_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    ready: 'Draft',
    scheduled: 'Scheduled',
    posted: 'Posted',
    archived: 'Archived',
    dropped: 'Archived',
};

export function studioPostStatus(status: string): string {
    if (status === 'ready' || status === 'dropped') {
        return status === 'ready' ? 'draft' : 'archived';
    }

    return status;
}
