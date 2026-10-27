import * as Dialog from '@radix-ui/react-dialog';
import { useCallback, useEffect, useRef, useState } from 'react';

export type MotionLightboxSlide = {
    filename: string;
    url: string;
};

type MotionLightboxProps = {
    slides: MotionLightboxSlide[];
    startIndex: number | null;
    onClose: () => void;
    onCloseAutoFocus?: (event: Event) => void;
};

// Shares the image lightbox shell (overlay, bar, stage, nav) so both pop-ups look the same.
export default function MotionLightbox({
    slides,
    startIndex,
    onClose,
    onCloseAutoFocus,
}: MotionLightboxProps) {
    return (
        <Dialog.Root
            open={startIndex !== null && slides.length > 0}
            onOpenChange={(next) => {
                if (!next) {
                    onClose();
                }
            }}
        >
            {startIndex !== null && slides.length > 0 ? (
                <MotionLightboxBody
                    key={startIndex}
                    slides={slides}
                    startIndex={startIndex}
                    onClose={onClose}
                    onCloseAutoFocus={onCloseAutoFocus}
                />
            ) : null}
        </Dialog.Root>
    );
}

function MotionLightboxBody({
    slides,
    startIndex,
    onClose,
    onCloseAutoFocus,
}: {
    slides: MotionLightboxSlide[];
    startIndex: number;
    onClose: () => void;
    onCloseAutoFocus?: (event: Event) => void;
}) {
    const [index, setIndex] = useState(startIndex);
    const [muted, setMuted] = useState(true);
    const videoRef = useRef<HTMLVideoElement>(null);
    const multi = slides.length > 1;
    const slide = slides[index];

    const step = useCallback(
        (delta: number) => {
            setIndex(
                (current) => (current + delta + slides.length) % slides.length,
            );
        },
        [slides.length],
    );

    useEffect(() => {
        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'ArrowLeft' || event.key === 'ArrowRight') {
                event.preventDefault();
                step(event.key === 'ArrowLeft' ? -1 : 1);
            }
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [step]);

    // Autoplay comes from the attribute: the portal mounts after this effect
    // would run, so the ref is still null on open. Cleanup pauses each
    // outgoing element on step, and the last one on close.
    useEffect(() => {
        const video = videoRef.current;

        return () => video?.pause();
    }, [index]);

    if (!slide) {
        return null;
    }

    return (
        <Dialog.Portal>
            <Dialog.Overlay className="image-lightbox-overlay" />
            <Dialog.Content
                className="image-lightbox"
                aria-describedby={undefined}
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <Dialog.Title className="sr-only">
                    {`Slide ${index + 1} of ${slides.length}`}
                </Dialog.Title>
                <div className="image-lightbox-bar">
                    <p className="image-lightbox-count">
                        {`${index + 1} / ${slides.length}`}
                    </p>
                    <div className="image-lightbox-zoom">
                        <button
                            type="button"
                            onClick={() => setMuted((current) => !current)}
                        >
                            {muted ? 'Sound on' : 'Sound off'}
                        </button>
                    </div>
                    <Dialog.Close className="image-lightbox-close">
                        Close
                    </Dialog.Close>
                </div>
                <div
                    className="image-lightbox-stage"
                    onClick={(event) => {
                        if (event.target === event.currentTarget) {
                            onClose();
                        }
                    }}
                >
                    {multi ? (
                        <button
                            type="button"
                            className="image-lightbox-nav image-lightbox-nav-prev"
                            onClick={() => step(-1)}
                            aria-label="Previous slide"
                        >
                            ‹
                        </button>
                    ) : null}
                    <figure className="image-lightbox-frame">
                        <video
                            key={index}
                            ref={videoRef}
                            className="motion-lightbox-video"
                            src={slide.url}
                            aria-label={slide.filename}
                            muted={muted}
                            autoPlay
                            loop
                            playsInline
                            preload="auto"
                            onClick={() => {
                                const video = videoRef.current;

                                if (video?.paused) {
                                    video.play().catch(() => undefined);
                                } else {
                                    video?.pause();
                                }
                            }}
                        />
                        <figcaption>{slide.filename}</figcaption>
                    </figure>
                    {multi ? (
                        <button
                            type="button"
                            className="image-lightbox-nav image-lightbox-nav-next"
                            onClick={() => step(1)}
                            aria-label="Next slide"
                        >
                            ›
                        </button>
                    ) : null}
                </div>
            </Dialog.Content>
        </Dialog.Portal>
    );
}
