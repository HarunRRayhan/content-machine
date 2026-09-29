import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

type Series = {
    id: number;
    slug: string;
    title: string;
    videos_count: number;
    preview: { part: number; title: string }[];
};

export default function SeriesIndex({ series }: { series: Series[] }) {
    return (
        <>
            <Head title="Video series" />
            <main className="studio-page series-page series-index-page">
                <div className="series-shell">
                    <Link href="/videos" className="series-back">
                        ← All videos
                    </Link>

                    <header className="series-page-heading">
                        <div>
                            <p className="series-overline">Video library</p>
                            <h1>Series</h1>
                            <p className="series-intro">
                                Connected videos, kept in their intended order.
                            </p>
                        </div>
                        <span className="series-index-total">
                            {series.length} series
                        </span>
                    </header>

                    {series.length === 0 ? (
                        <div className="series-empty">
                            <strong>No series yet</strong>
                            <p>
                                Series will appear here when videos are grouped.
                            </p>
                        </div>
                    ) : (
                        <ul className="series-catalog">
                            {series.map((item) => (
                                <li key={item.id}>
                                    <Link
                                        href={`/series/${item.slug}`}
                                        className="series-catalog-card"
                                    >
                                        <div className="series-catalog-copy">
                                            <span className="series-catalog-label">
                                                Video series
                                            </span>
                                            <h2>{item.title}</h2>
                                            <div className="series-catalog-footer">
                                                <span className="series-catalog-count">
                                                    <strong>
                                                        {item.videos_count}
                                                    </strong>
                                                    <span>
                                                        {item.videos_count === 1
                                                            ? 'part'
                                                            : 'parts'}
                                                    </span>
                                                </span>
                                                <span className="series-catalog-action">
                                                    View all parts
                                                    <ArrowUpRight aria-hidden="true" />
                                                </span>
                                            </div>
                                        </div>
                                        <div className="series-catalog-preview">
                                            <span className="series-catalog-preview-heading">
                                                First parts
                                            </span>
                                            {item.preview.length === 0 ? (
                                                <p>No parts yet</p>
                                            ) : (
                                                <ol>
                                                    {item.preview.map(
                                                        (video) => (
                                                            <li
                                                                key={video.part}
                                                            >
                                                                <span>
                                                                    {String(
                                                                        video.part,
                                                                    ).padStart(
                                                                        2,
                                                                        '0',
                                                                    )}
                                                                </span>
                                                                <strong>
                                                                    {
                                                                        video.title
                                                                    }
                                                                </strong>
                                                            </li>
                                                        ),
                                                    )}
                                                </ol>
                                            )}
                                            {item.videos_count >
                                                item.preview.length && (
                                                <span className="series-catalog-more">
                                                    +
                                                    {item.videos_count -
                                                        item.preview
                                                            .length}{' '}
                                                    more
                                                </span>
                                            )}
                                        </div>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </main>
        </>
    );
}
