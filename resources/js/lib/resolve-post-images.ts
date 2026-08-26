/**
 * Pick the images for one platform tab. An empty list is an explicit
 * "none" (English Twitter, etc.) and must stay empty. Never fall back
 * to every file attached to the post.
 */
export function resolvePostImages(
    platformImages: string[],
    imageUrls: Record<string, string>,
): Array<{ name: string; url: string | null }> {
    return platformImages.map((name) => ({
        name,
        url: lookupImageUrl(name, imageUrls),
    }));
}

function lookupImageUrl(
    name: string,
    imageUrls: Record<string, string>,
): string | null {
    return imageUrls[name] ?? imageUrls[name.split('/').pop() ?? ''] ?? null;
}

/**
 * LinkedIn 2+ slides ship as one document-post PDF. Prefer a filename
 * already listed on the platform, then the `*-linkedin-carousel.pdf`
 * convention used by Script Studio / publish.
 */
export function resolveLinkedinCarouselPdf(
    platformImages: string[],
    imageUrls: Record<string, string>,
): { name: string; url: string } | null {
    const candidates: string[] = [];

    for (const name of platformImages) {
        if (/\.pdf$/i.test(name)) {
            candidates.push(name);
        }
    }

    for (const name of Object.keys(imageUrls)) {
        if (/-linkedin-carousel\.pdf$/i.test(name)) {
            candidates.push(name);
        }
    }

    for (const name of candidates) {
        const url = lookupImageUrl(name, imageUrls);

        if (url) {
            return { name, url };
        }
    }

    return null;
}
