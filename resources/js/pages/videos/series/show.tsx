import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight } from 'lucide-react';

type Part = { human_id: string; title: string; part: number; status: string };
type Series = { slug: string; title: string; videos: Part[] };

export default function SeriesShow({ series }: { series: Series }) {
    return (
        <>
            <Head title={series.title} />
            <main className="studio-page series-page series-show-page">
                <div className="series-shell">
                    <Link href="/series" className="series-back">
                        ← All series
                    </Link>

                    <header className="series-show-heading">
                        <div>
                            <p className="series-overline">Video series</p>
                            <h1>{series.title}</h1>
                            <p className="series-intro">
                                Follow the videos in order, or open any part.
                            </p>
                        </div>
                        <div className="series-show-count">
                            <strong>{series.videos.length}</strong>
                            <span>
                                {series.videos.length === 1 ? 'part' : 'parts'}
                            </span>
                        </div>
                    </header>

                    <section
                        className="series-parts"
                        aria-labelledby="series-parts-heading"
                    >
                        <div className="series-parts-heading">
                            <h2 id="series-parts-heading">All parts</h2>
                            <span>In order</span>
                        </div>
                        <ol className="series-timeline">
                            {series.videos.map((video) => (
                                <li key={video.human_id}>
                                    <Link href={`/videos/${video.human_id}`}>
                                        <span
                                            className="series-step-number"
                                            aria-hidden="true"
                                        >
                                            {String(video.part).padStart(
                                                2,
                                                '0',
                                            )}
                                        </span>
                                        <span className="series-step-copy">
                                            <span className="series-step-meta">
                                                Part {video.part}
                                                <span aria-hidden="true">
                                                    ·
                                                </span>
                                                {video.human_id}
                                            </span>
                                            <strong>{video.title}</strong>
                                        </span>
                                        <span
                                            className="series-step-status"
                                            data-status={video.status}
                                        >
                                            {video.status.replaceAll('_', ' ')}
                                        </span>
                                        <ArrowUpRight
                                            className="series-step-arrow"
                                            aria-hidden="true"
                                        />
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    </section>
                </div>
            </main>
        </>
    );
}
