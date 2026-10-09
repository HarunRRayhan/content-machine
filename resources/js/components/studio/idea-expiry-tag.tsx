import { ideaExpiry } from '@/lib/studio-meta';

/** "expires in Nd" / "Stale" tag for an idea row; renders nothing for evergreen. */
export default function IdeaExpiryTag({
    expiresAt,
}: {
    expiresAt: string | null;
}) {
    const expiry = ideaExpiry(expiresAt);

    if (!expiry) {
        return null;
    }

    return (
        <span className={`idea-expiry${expiry.stale ? 'is-stale' : ''}`}>
            {' '}
            · {expiry.label}
        </span>
    );
}
