export type SourceLink = {
    url: string;
    label: string | null;
};

type Props = {
    links: SourceLink[];
    text: string | null;
};

/**
 * Read-only Source tab body shared by ideas, posts and videos: the original
 * links and raw text a piece of content grew out of, kept for later research.
 */
export default function SourcePanel({ links, text }: Props) {
    const hasLinks = links.length > 0;
    const hasText = !!text && text.trim() !== '';

    return (
        <>
            <section className="pane">
                <div className="pane-head">
                    <span className="k">
                        🔗 <b>Source links</b>
                    </span>
                </div>
                {hasLinks ? (
                    <ul className="source-links">
                        {links.map((link) => (
                            <li key={link.url}>
                                <a
                                    href={link.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    {link.label || link.url}
                                </a>
                                {link.label && (
                                    <span className="source-url">
                                        {link.url}
                                    </span>
                                )}
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="empty">No source links saved.</p>
                )}
            </section>

            <section className="pane">
                <div className="pane-head">
                    <span className="k">
                        📄 <b>Source text</b>
                    </span>
                </div>
                {hasText ? (
                    <div className="scratch source-text">{text}</div>
                ) : (
                    <p className="empty">No source text saved.</p>
                )}
            </section>
        </>
    );
}
