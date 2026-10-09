export function scoreBand(score: number | null): 'hi' | 'mid' | 'lo' {
    if (score === null) {
        return 'lo';
    }

    if (score >= 800) {
        return 'hi';
    }

    if (score >= 500) {
        return 'mid';
    }

    return 'lo';
}

export type TrendKind = 'evergreen' | 'seasonal' | 'short-trend' | '';

export function trendKind(trend: string | null): TrendKind {
    if (!trend) {
        return '';
    }

    const value = trend.toLowerCase();

    if (value.includes('short')) {
        return 'short-trend';
    }

    if (value.includes('seasonal')) {
        return 'seasonal';
    }

    if (value.includes('evergreen')) {
        return 'evergreen';
    }

    return '';
}

export function trendLabel(trend: string | null): string {
    const kind = trendKind(trend);

    if (kind === 'short-trend') {
        return '🟡 Short trend';
    }

    if (kind === 'seasonal') {
        return '🔴 Seasonal';
    }

    if (kind === 'evergreen') {
        return '🟢 Evergreen';
    }

    return trend ?? '';
}

export type IdeaExpiry = { label: string; stale: boolean };

/**
 * Shelf-life badge for an idea: "expires in Nd" until `expires_at`, then
 * "Stale". Null when the idea never expires (evergreen or unset trend).
 */
export function ideaExpiry(
    expiresAt: string | null,
    now: Date = new Date(),
): IdeaExpiry | null {
    if (!expiresAt) {
        return null;
    }

    const msLeft = new Date(expiresAt).getTime() - now.getTime();

    if (Number.isNaN(msLeft)) {
        return null;
    }

    if (msLeft <= 0) {
        return { label: 'Stale', stale: true };
    }

    return {
        label: `expires in ${Math.ceil(msLeft / 86_400_000)}d`,
        stale: false,
    };
}
