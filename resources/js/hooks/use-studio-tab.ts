import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';

/**
 * Keep a Studio show-page tab in sync with `?tab=` so shared links open the
 * right panel (e.g. /posts/P-59?tab=captions).
 */
export function useStudioTab<T extends string>(
    validTabs: readonly T[],
    fallback: T,
): [T, (next: T) => void] {
    const page = usePage();
    const [tab, setTabState] = useState<T>(() =>
        resolveTab(page.url, validTabs, fallback),
    );

    const setTab = useCallback(
        (next: T) => {
            if (!validTabs.includes(next)) {
                return;
            }

            setTabState(next);
            replaceTabQuery(next, fallback);
        },
        [validTabs, fallback],
    );

    return [tab, setTab];
}

function resolveTab<T extends string>(
    pageUrl: string,
    validTabs: readonly T[],
    fallback: T,
): T {
    try {
        const url = new URL(
            pageUrl,
            typeof window !== 'undefined'
                ? window.location.origin
                : 'http://localhost',
        );
        const raw = url.searchParams.get('tab');

        if (raw && (validTabs as readonly string[]).includes(raw)) {
            return raw as T;
        }
    } catch {
        // ignore malformed page.url
    }

    return fallback;
}

function replaceTabQuery(tab: string, fallback: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    const url = new URL(window.location.href);

    if (tab === fallback) {
        url.searchParams.delete('tab');
    } else {
        url.searchParams.set('tab', tab);
    }

    window.history.replaceState(
        window.history.state,
        '',
        `${url.pathname}${url.search}${url.hash}`,
    );
}
