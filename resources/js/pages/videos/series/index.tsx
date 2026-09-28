import { Head, Link } from '@inertiajs/react';

type Series = { id: number; slug: string; title: string; videos_count: number };

export default function SeriesIndex({ series }: { series: Series[] }) {
    return (
        <>
            <Head title="Video series" />
            <main className="studio-page series-page p-4">
                <Link href="/videos" className="back">
                    ← All videos
                </Link>
                <h1>Video series</h1>
                {series.length === 0 ? (
                    <p>No video series yet.</p>
                ) : (
                    <ul className="series-list">
                        {series.map((item) => (
                            <li key={item.id}>
                                <Link href={`/series/${item.slug}`}>
                                    <strong>{item.title}</strong>
                                    <span>{item.videos_count} parts</span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </main>
        </>
    );
}
