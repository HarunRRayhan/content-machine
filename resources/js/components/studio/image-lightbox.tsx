import * as Dialog from '@radix-ui/react-dialog';
import { useCallback, useEffect, useRef, useState } from 'react';

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
const SWIPE_THRESHOLD_PX = 48;

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
                    onClose={onClose}
                />
            ) : null}
        </Dialog.Root>
    );
}

function LightboxBody({
    images,
    startIndex,
    onClose,
}: {
    images: LightboxImage[];
    startIndex: number;
    onClose: () => void;
}) {
    const [zoom, setZoom] = useState(1);
    const [currentIndex, setCurrentIndex] = useState(() =>
        clampIndex(startIndex, images.length),
    );
    const pointerStartX = useRef<number | null>(null);
    const multi = images.length > 1;
    const image = images[currentIndex] ?? images[0];

    const goTo = useCallback(
        (index: number) => {
            setCurrentIndex(clampIndex(index, images.length));
            setZoom(1);
        },
        [images.length],
    );

    const goPrev = useCallback(() => {
        if (images.length <= 1) {
            return;
        }

        setCurrentIndex((current) =>
            current <= 0 ? images.length - 1 : current - 1,
        );
        setZoom(1);
    }, [images.length]);

    const goNext = useCallback(() => {
        if (images.length <= 1) {
            return;
        }

        setCurrentIndex((current) =>
            current >= images.length - 1 ? 0 : current + 1,
        );
        setZoom(1);
    }, [images.length]);

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

            if (event.key === 'ArrowLeft') {
                event.preventDefault();
                goPrev();
            }

            if (event.key === 'ArrowRight') {
                event.preventDefault();
                goNext();
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [goPrev, goNext]);

    useEffect(() => {
        const onWheel = (event: WheelEvent) => {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }

            event.preventDefault();
            const direction = event.deltaY < 0 ? 1 : -1;
            setZoom((current) => clampZoom(current + direction * ZOOM_STEP));
        };

        window.addEventListener('wheel', onWheel, { passive: false });

        return () => window.removeEventListener('wheel', onWheel);
    }, []);

    if (!image) {
        return null;
    }

    return (
        <Dialog.Portal>
            <Dialog.Overlay className="image-lightbox-overlay" />
            <Dialog.Content
                className="image-lightbox"
                aria-describedby={undefined}
                onPointerDown={(event) => {
                    const target = event.target;

                    if (!(target instanceof Element)) {
                        return;
                    }

                    if (target.closest('button')) {
                        return;
                    }

                    onClose();
                }}
            >
                <Dialog.Title className="sr-only">
                    {multi
                        ? `Image ${currentIndex + 1} of ${images.length}`
                        : image.name}
                </Dialog.Title>
                <div className="image-lightbox-bar">
                    <p className="image-lightbox-count">
                        {multi
                            ? `${currentIndex + 1} / ${images.length}`
                            : image.name}
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
                    className="image-lightbox-stage"
                    style={{ ['--lb-zoom' as string]: String(zoom) }}
                    onPointerDown={(event) => {
                        if (
                            event.pointerType === 'mouse' &&
                            event.button !== 0
                        ) {
                            return;
                        }

                        pointerStartX.current = event.clientX;
                    }}
                    onPointerUp={(event) => {
                        const start = pointerStartX.current;
                        pointerStartX.current = null;

                        if (start === null || !multi || zoom > 1) {
                            return;
                        }

                        const delta = event.clientX - start;

                        if (Math.abs(delta) < SWIPE_THRESHOLD_PX) {
                            return;
                        }

                        if (delta < 0) {
                            goNext();
                        } else {
                            goPrev();
                        }
                    }}
                    onPointerCancel={() => {
                        pointerStartX.current = null;
                    }}
                >
                    {multi ? (
                        <button
                            type="button"
                            className="image-lightbox-nav image-lightbox-nav-prev"
                            onClick={goPrev}
                            aria-label="Previous image"
                        >
                            ‹
                        </button>
                    ) : null}
                    <figure className="image-lightbox-frame">
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
                    {multi ? (
                        <button
                            type="button"
                            className="image-lightbox-nav image-lightbox-nav-next"
                            onClick={goNext}
                            aria-label="Next image"
                        >
                            ›
                        </button>
                    ) : null}
                </div>
                {multi ? (
                    <div className="image-lightbox-thumbs" role="tablist">
                        {images.map((thumb, index) => (
                            <button
                                key={`${thumb.url}-${index}`}
                                type="button"
                                role="tab"
                                aria-selected={index === currentIndex}
                                className="image-lightbox-thumb"
                                data-active={
                                    index === currentIndex ? 'true' : 'false'
                                }
                                onClick={() => goTo(index)}
                            >
                                <img src={thumb.url} alt={thumb.name} />
                            </button>
                        ))}
                    </div>
                ) : null}
            </Dialog.Content>
        </Dialog.Portal>
    );
}

function clampZoom(value: number): number {
    return Math.min(MAX_ZOOM, Math.max(MIN_ZOOM, Math.round(value * 4) / 4));
}

function clampIndex(index: number, length: number): number {
    if (length <= 0) {
        return 0;
    }

    return Math.min(Math.max(index, 0), length - 1);
}
