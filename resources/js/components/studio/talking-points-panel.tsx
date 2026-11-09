import { useEffect, useRef, useState } from 'react';

import { isTypingTarget } from '@/lib/is-typing-target';

type Point = {
    label: string;
    text: string;
};

type Props = {
    points: Point[];
};

const NEXT_KEYS = new Set(['ArrowDown', 'ArrowRight']);
const PREV_KEYS = new Set(['ArrowUp', 'ArrowLeft']);

export default function TalkingPointsPanel({ points }: Props) {
    const panelRef = useRef<HTMLElement>(null);
    // points.length means every point is marked done.
    const [current, setCurrent] = useState(0);

    function toggleFullscreen() {
        if (document.fullscreenElement) {
            document.exitFullscreen().catch(() => undefined);
        } else {
            panelRef.current?.requestFullscreen().catch(() => undefined);
        }
    }

    useEffect(() => {
        function onKeyDown(event: KeyboardEvent) {
            if (
                isTypingTarget(event.target) ||
                event.altKey ||
                event.ctrlKey ||
                event.metaKey
            ) {
                return;
            }

            if (NEXT_KEYS.has(event.key)) {
                event.preventDefault();
                setCurrent((index) => Math.min(index + 1, points.length));
            } else if (PREV_KEYS.has(event.key)) {
                event.preventDefault();
                setCurrent((index) => Math.max(index - 1, 0));
            } else if (event.key === 'f' || event.key === 'F') {
                event.preventDefault();
                toggleFullscreen();
            }
        }

        window.addEventListener('keydown', onKeyDown);

        return () => window.removeEventListener('keydown', onKeyDown);
    }, [points.length]);

    let number = 0;

    return (
        <section
            ref={panelRef}
            className="tp-panel"
            aria-label="Talking points"
        >
            <div className="tp-toolbar">
                <span>↑ ↓ or ← → to move · F for full screen</span>
                <button type="button" onClick={toggleFullscreen}>
                    Full screen
                </button>
            </div>
            <ol className="tp-list">
                {points.map((point, index) => {
                    const done = index < current;
                    const marker = point.label || String(++number);

                    return (
                        <li
                            key={`${marker}-${point.text}`}
                            className={done ? 'tp-item is-done' : 'tp-item'}
                            aria-current={
                                index === current ? 'step' : undefined
                            }
                        >
                            <span className="tp-mark">
                                {done ? '✓' : marker}
                            </span>
                            <span className="tp-text">{point.text}</span>
                        </li>
                    );
                })}
            </ol>
        </section>
    );
}
