/**
 * Short show URLs. A prefixed segment (P-50, BV-46) binds to human_id.
 */
export function postShowUrl(humanId: string): string {
    return `/posts/${humanId}`;
}

export function videoShowUrl(humanId: string): string {
    return `/videos/${humanId}`;
}
