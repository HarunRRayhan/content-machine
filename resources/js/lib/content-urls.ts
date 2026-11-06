/**
 * Short show URLs. A video segment (BV-46) binds to human_id; a post segment is its number (P- optional).
 */
export function postShowUrl(humanId: string): string {
    // The P- prefix is a display label; P-290 and 290 open the same post.
    return `/posts/${humanId.replace(/^P-(\d+)$/i, '$1')}`;
}

export function videoShowUrl(humanId: string): string {
    return `/videos/${humanId}`;
}
