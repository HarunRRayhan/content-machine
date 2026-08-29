import * as Dialog from '@radix-ui/react-dialog';
import { useEffect, useRef, useState } from 'react';

export type LightboxImage = {
    name: string;
    url: string;
};

type SourceImage = {
    filename?: string;
    name?: string;
    url?: string | null;
    mime?: string | null;
};

const MIN_ZOOM = 1;
const MAX_ZOOM = 4;
const ZOOM_STEP = 0.25;

export function toLightboxImages(images: SourceImage[]): LightboxImage[] {
    const out: LightboxImage[] = [];

    for (const image of images) {
        if (!image.url) {
            continue;
        }

        if (image.mime && !image.mime.startsWith('image/')) {
            continue;
        }

        const name = image.filename ?? image.name ?? '';

        if (!image.mime && /\.pdf$/i.test(name)) {
            continue;
        }

        out.push({ name: name || 'Image', url: image.url });
    }

    return out;
}

type ImageLightboxProps = {
    images: LightboxImage[];
    startIndex: number | null;
    onClose: () => void;
};

export default function ImageLightbox({
    images,
    startIndex,
    onClose,
}: ImageLightboxProps) {
    const open = startIndex !== null && startIndex >= 0 && images.length > 0;

    return (
        <Dialog.Root
            open={open}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            {open && startIndex !== null ? (
                <LightboxBody
                    key={startIndex}
                    images={images}
                    startIndex={startIndex}
                />
            ) : null}
        </Dialog.Root>
    );
}

function LightboxBody({
    images,
    startIndex,
}: {
    images: LightboxImage[];
    startIndex: number;
}) {
    const [zoom, setZoom] = useState(1);
    const [currentIndex, setCurrentIndex] = useState(() =>
        Math.min(Math.max(startIndex, 0), images.length - 1),
    );
    const scrollerRef = useRef<HTMLDivElement>(null);
    const frameRefs = useRef<Array<HTMLElement | null>>([]);
    const readyForObserverRef = useRef(false);

    useEffect(() => {
        const index = Math.min(Math.max(startIndex, 0), images.length - 1);
        const scroller = scrollerRef.current;
        const frame = frameRefs.current[index];

        readyForObserverRef.current = false;

        // scrollIntoView inside a Radix Dialog portal often scrolls the wrong
        // ancestor, so the first frame stays on screen and the intersection
        // observer reports image 1 no matter which thumb was clicked.
        // currentIndex is seeded from startIndex via useState + key={startIndex}.
        window.requestAnimationFrame(() => {
            if (scroller && frame) {
                scroller.scrollTop = Math.max(
                    0,
                    frame.offsetTop - scroller.clientTop - 8,
                );
            }

            window.requestAnimationFrame(() => {
                readyForObserverRef.current = true;
            });
        });
    }, [startIndex, images.length]);

    useEffect(() => {
        const frames = frameRefs.current.filter(
            (node): node is HTMLElement => node !== null,
        );

        if (frames.length === 0) {
            return;
        }

        const observer = new IntersectionObserver(
            (entries) => {
                if (!readyForObserverRef.current) {
                    return;
                }

                const visible = entries
                    .filter((entry) => entry.isIntersecting)
                    .sort(
                        (left, right) =>
                            right.intersectionRatio - left.intersectionRatio,
                    )[0];

                if (!visible) {
                    return;
                }

                const index = frames.indexOf(visible.target as HTMLElement);

                if (index >= 0) {
                    setCurrentIndex(index);
                }
            },
            { root: scrollerRef.current, threshold: 0.55 },
        );

        for (const frame of frames) {
            observer.observe(frame);
        }

        return () => observer.disconnect();
    }, [images.length]);

    useEffect(() => {
        const node = scrollerRef.current;

        if (node === null) {
            return;
        }

        const onWheel = (event: WheelEvent) => {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }

            event.preventDefault();
            const direction = event.deltaY < 0 ? 1 : -1;
            setZoom((current) => clampZoom(current + direction * ZOOM_STEP));
        };

        node.addEventListener('wheel', onWheel, { passive: false });

        return () => node.removeEventListener('wheel', onWheel);
    }, []);

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === '+' || event.key === '=') {
                event.preventDefault();
                setZoom((current) => clampZoom(current + ZOOM_STEP));
            }

            if (event.key === '-' || event.key === '_') {
                event.preventDefault();
                setZoom((current) => clampZoom(current - ZOOM_STEP));
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, []);

    return (
        <Dialog.Portal>
            <Dialog.Overlay className="image-lightbox-overlay" />
            <Dialog.Content
                className="image-lightbox"
                aria-describedby={undefined}
            >
                <Dialog.Title className="sr-only">
                    {images.length > 1
                        ? `${images.length} images`
                        : (images[0]?.name ?? 'Image')}
                </Dialog.Title>
                <div className="image-lightbox-bar">
                    <p className="image-lightbox-count">
                        {images.length > 1
                            ? `${currentIndex + 1} / ${images.length}`
                            : (images[0]?.name ?? 'Image')}
                    </p>
                    <div className="image-lightbox-zoom">
                        <button
                            type="button"
                            onClick={() =>
                                setZoom((current) =>
                                    clampZoom(current - ZOOM_STEP),
                                )
                            }
                            disabled={zoom <= MIN_ZOOM}
                        >
                            −
                        </button>
                        <span>{Math.round(zoom * 100)}%</span>
                        <button
                            type="button"
                            onClick={() =>
                                setZoom((current) =>
                                    clampZoom(current + ZOOM_STEP),
                                )
                            }
                            disabled={zoom >= MAX_ZOOM}
                        >
                            +
                        </button>
                    </div>
                    <Dialog.Close className="image-lightbox-close">
                        Close
                    </Dialog.Close>
                </div>
                <div
                    ref={scrollerRef}
                    className="image-lightbox-scroller"
                    style={{ ['--lb-zoom' as string]: String(zoom) }}
                >
                    {images.map((image, index) => (
                        <figure
                            key={`${image.url}-${index}`}
                            ref={(node) => {
                                frameRefs.current[index] = node;
                            }}
                            className="image-lightbox-frame"
                        >
                            <button
                                type="button"
                                className="image-lightbox-shot"
                                data-zoomed={zoom > 1 ? 'true' : 'false'}
                                onClick={() =>
                                    setZoom((current) => (current > 1 ? 1 : 2))
                                }
                            >
                                <img src={image.url} alt={image.name} />
                            </button>
                            <figcaption>{image.name}</figcaption>
                        </figure>
                    ))}
                </div>
            </Dialog.Content>
        </Dialog.Portal>
    );
}

function clampZoom(value: number): number {
    return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(value * 4) / 4));
}
