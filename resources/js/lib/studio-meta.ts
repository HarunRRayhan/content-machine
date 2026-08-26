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

export function trendKind(trend: string | null): 'evergreen' | 'seasonal' | '' {
    if (!trend) {
        return '';
    }

    const value = trend.toLowerCase();

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

    if (kind === 'seasonal') {
        return '🔴 Seasonal';
    }

    if (kind === 'evergreen') {
        return '🟢 Evergreen';
    }

    return trend ?? '';
}
