import { useEffect, useRef } from 'react';

type Props = {
    src: string;
    title: string;
};

const PRES_KEYS = new Set([' ', 'ArrowRight', 'ArrowLeft', 'r', 'R', 'f', 'F']);

export default function PresentationEmbed({ src, title }: Props) {
    const iframeRef = useRef<HTMLIFrameElement>(null);
    useEffect(() => {
        iframeRef.current?.focus();
    }, [src]);

    useEffect(() => {
        function isTyping(target: EventTarget | null): boolean {
            if (!(target instanceof HTMLElement)) {
                return false;
            }

            const tag = target.tagName;

            return (
                tag === 'INPUT' ||
                tag === 'TEXTAREA' ||
                tag === 'SELECT' ||
                target.isContentEditable
            );
        }

        function post(payload: Record<string, unknown>) {
            const frame = iframeRef.current?.contentWindow;

            if (!frame) {
                return;
            }

            frame.postMessage({ source: 'cm-pres', ...payload }, '*');
        }

        function onKeyDown(event: KeyboardEvent) {
            if (isTyping(event.target)) {
                return;
            }

            if (!PRES_KEYS.has(event.key)) {
                return;
            }

            event.preventDefault();

            if (event.key === 'f' || event.key === 'F') {
                const iframe = iframeRef.current;

                if (!iframe) {
                    return;
                }

                if (!document.fullscreenElement) {
                    iframe.requestFullscreen().catch(() => undefined);
                } else {
                    document.exitFullscreen().catch(() => undefined);
                }

                return;
            }

            post({ type: 'key', key: event.key });
        }

        function onFullscreenChange() {
            post({
                type: 'fs',
                on: document.fullscreenElement === iframeRef.current,
            });
        }

        window.addEventListener('keydown', onKeyDown);
        document.addEventListener('fullscreenchange', onFullscreenChange);

        return () => {
            window.removeEventListener('keydown', onKeyDown);
            document.removeEventListener(
                'fullscreenchange',
                onFullscreenChange,
            );
        };
    }, []);

    return (
        <iframe
            ref={iframeRef}
            className="pres-embed"
            src={src}
            title={`${title} presentation`}
            allow="fullscreen"
            allowFullScreen
            tabIndex={0}
        />
    );
}
