import { router, usePage } from '@inertiajs/react';
import { useCallback, useLayoutEffect, useState } from 'react';

/**
 * Keep a Studio show-page tab in sync with `?tab=` so shared links open the
 * right panel (e.g. /posts/P-59?tab=captions).
 */
export function useStudioTab<T extends string>(
    validTabs: readonly T[],
    fallback: T,
): [T, (next: T) => void] {
    const page = usePage();
    const currentUrl = browserUrl(page.url);
    const [selection, setSelection] = useState<{ pageUrl: string; tab: T }>(
        () => ({
            pageUrl: currentUrl,
            tab: resolveTab(currentUrl, validTabs, fallback),
        }),
    );
    const tab =
        selection.pageUrl === currentUrl && validTabs.includes(selection.tab)
            ? selection.tab
            : resolveTab(currentUrl, validTabs, fallback);

    useLayoutEffect(() => {
        if (hasInvalidTabQuery(currentUrl, validTabs)) {
            replaceTabQuery(fallback, fallback);
        }
    }, [currentUrl, validTabs, fallback]);

    const setTab = useCallback(
        (next: T) => {
            if (!validTabs.includes(next)) {
                return;
            }

            setSelection({ pageUrl: currentUrl, tab: next });
            replaceTabQuery(next, fallback);
        },
        [currentUrl, validTabs, fallback],
    );

    return [tab, setTab];
}

function browserUrl(pageUrl: string): string {
    return typeof window !== 'undefined' ? window.location.href : pageUrl;
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

    router.replace({
        url: `${url.pathname}${url.search}${url.hash}`,
        preserveScroll: true,
        preserveState: true,
    });
}

function hasInvalidTabQuery<T extends string>(
    pageUrl: string,
    validTabs: readonly T[],
): boolean {
    try {
        const url = new URL(
            pageUrl,
            typeof window !== 'undefined'
                ? window.location.origin
                : 'http://localhost',
        );
        const raw = url.searchParams.get('tab');

        return raw !== null && !(validTabs as readonly string[]).includes(raw);
    } catch {
        return false;
    }
}
