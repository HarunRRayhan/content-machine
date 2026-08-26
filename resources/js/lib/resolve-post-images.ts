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
        url: imageUrls[name] ?? imageUrls[name.split('/').pop() ?? ''] ?? null,
    }));
}
