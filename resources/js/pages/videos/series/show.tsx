import { Head, Link } from '@inertiajs/react';

type Part = { human_id: string; title: string; part: number; status: string };
type Series = { slug: string; title: string; videos: Part[] };

export default function SeriesShow({ series }: { series: Series }) {
    return (
        <>
            <Head title={series.title} />
            <main className="studio-page series-page p-4">
                <Link href="/series" className="back">
                    ← All series
                </Link>
                <h1>{series.title}</h1>
                <p>{series.videos.length} parts</p>
                <ol className="series-list">
                    {series.videos.map((video) => (
                        <li key={video.human_id}>
                            <Link href={`/videos/${video.human_id}`}>
                                <span className="series-part">
                                    Part {video.part}
                                </span>
                                <strong>{video.title}</strong>
                                <span>{video.status}</span>
                            </Link>
                        </li>
                    ))}
                </ol>
            </main>
        </>
    );
}
