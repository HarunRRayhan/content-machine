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
    { badge: string; color: string; labels: { en: string; bn: string } }
> = {
    facebook: {
        badge: 'f',
        color: '#1877F2',
        labels: { en: 'Facebook', bn: 'ফেসবুক' },
    },
    instagram: {
        badge: 'IG',
        color: '#E4405F',
        labels: { en: 'Instagram', bn: 'ইনস্টাগ্রাম' },
    },
    tiktok: {
        badge: 'TT',
        color: '#000000',
        labels: { en: 'TikTok', bn: 'টিকটক' },
    },
    youtube: {
        badge: 'YT',
        color: '#FF0000',
        labels: { en: 'YouTube', bn: 'ইউটিউব' },
    },
    twitter: {
        badge: 'X',
        color: '#000000',
        labels: { en: 'Twitter', bn: 'টুইটার' },
    },
    threads: {
        badge: '@',
        color: '#000000',
        labels: { en: 'Threads', bn: 'থ্রেডস' },
    },
    bluesky: {
        badge: 'BS',
        color: '#1185FE',
        labels: { en: 'Bluesky', bn: 'ব্লুস্কাই' },
    },
    linkedin: {
        badge: 'in',
        color: '#0A66C2',
        labels: { en: 'LinkedIn', bn: 'লিংকডইন' },
    },
};

export function normalizePlatformKey(name: string): PlatformKey | null {
    const key = name.trim().toLowerCase();

    if (key in PLATFORM_META) {
        return key as PlatformKey;
    }

    return null;
}

export function platformLabel(
    name: string,
    lang: string | null | undefined,
): string {
    const key = normalizePlatformKey(name);

    if (key === null) {
        return name;
    }

    return lang === 'bn'
        ? PLATFORM_META[key].labels.bn
        : PLATFORM_META[key].labels.en;
}

/** English leads with Twitter; Bangla leads with Facebook. */
export function leadPlatformForLang(
    lang: string | null | undefined,
): PlatformKey | null {
    if (lang === 'en') {
        return 'twitter';
    }

    if (lang === 'bn') {
        return 'facebook';
    }

    return null;
}

export function orderPlatformsByLang<T extends { name: string }>(
    platforms: T[],
    lang: string | null | undefined,
): T[] {
    const lead = leadPlatformForLang(lang);

    if (lead === null) {
        return platforms;
    }

    const first = platforms.filter(
        (platform) => normalizePlatformKey(platform.name) === lead,
    );
    const rest = platforms.filter(
        (platform) => normalizePlatformKey(platform.name) !== lead,
    );

    return [...first, ...rest];
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
