export type HandlePreview = {
    handle: string;
    name: string;
};

export type HandleDirectory = {
    bn: Record<string, HandlePreview>;
    en: Record<string, HandlePreview>;
};

const DISPLAY_NAME = 'Harun R. Rayhan';

/**
 * Copied from personal-content/web/workspaces.json. Script Studio
 * bakes this into WORKSPACES and looks up handles from it. Content
 * Machine uses the same map when settings or the live API omit one.
 */
export const STUDIO_HANDLES: HandleDirectory = {
    bn: {
        facebook: { handle: 'HarunRRayhan', name: DISPLAY_NAME },
        instagram: { handle: 'harunrrayhan', name: DISPLAY_NAME },
        tiktok: { handle: 'harunrrayhan', name: DISPLAY_NAME },
        youtube: { handle: 'skillupwithharun', name: DISPLAY_NAME },
        twitter: { handle: 'HarunRRayhan', name: DISPLAY_NAME },
        threads: { handle: 'harunrrayhan', name: DISPLAY_NAME },
        bluesky: { handle: 'harunrrayhan.bsky.social', name: DISPLAY_NAME },
        linkedin: { handle: '', name: DISPLAY_NAME },
    },
    en: {
        facebook: { handle: 'harundotdev', name: DISPLAY_NAME },
        instagram: { handle: 'harundotdev', name: DISPLAY_NAME },
        tiktok: { handle: 'harundotdev', name: DISPLAY_NAME },
        youtube: { handle: 'harundotdev', name: DISPLAY_NAME },
        twitter: { handle: 'harundotdev', name: DISPLAY_NAME },
        threads: { handle: 'harundotdev', name: DISPLAY_NAME },
        bluesky: { handle: 'harun.dev', name: DISPLAY_NAME },
        linkedin: { handle: 'harundotdev', name: DISPLAY_NAME },
    },
};
