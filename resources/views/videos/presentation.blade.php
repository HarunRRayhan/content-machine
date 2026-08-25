<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $video->human_id }} · Presentation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/reveal.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/theme/black.css">
    <style>
        html, body { margin: 0; height: 100%; background: #0b0b0b; color: #f5f5f5; font-family: ui-sans-serif, system-ui, sans-serif; }
        #shell { display: grid; grid-template-columns: minmax(0, 1fr) 340px; height: 100vh; gap: 0; }
        #stageWrap { display: grid; place-items: center; background: #111; min-width: 0; }
        #stage { width: min(100vmin, 100%); aspect-ratio: 1 / 1; background: #000; position: relative; overflow: hidden; }
        .reveal { height: 100%; width: 100%; }
        #notes { border-left: 1px solid #2a2a2a; padding: 16px; overflow: auto; background: #141414; }
        #notes h1 { font-size: 14px; margin: 0 0 8px; color: #aaa; font-weight: 600; letter-spacing: .04em; text-transform: uppercase; }
        #cue { font-size: 18px; line-height: 1.45; white-space: pre-wrap; }
        #meta { margin-top: 16px; font-size: 12px; color: #888; }
        #toolbar { position: fixed; top: 12px; left: 12px; display: flex; gap: 8px; z-index: 50; }
        #toolbar a, #toolbar button {
            background: rgba(0,0,0,.7); color: #fff; border: 1px solid #444; border-radius: 8px;
            padding: 8px 12px; text-decoration: none; font-size: 13px; cursor: pointer;
        }
        @media (max-width: 900px) {
            #shell { grid-template-columns: 1fr; grid-template-rows: minmax(0, 1fr) 40%; }
            #notes { border-left: 0; border-top: 1px solid #2a2a2a; }
        }
        {!! $manifest['css'] ?? '' !!}
    </style>
</head>
<body>
    <div id="toolbar">
        <a href="{{ route('dashboard.videos.show', $video) }}">← Back</a>
        <button type="button" id="fsBtn">Fullscreen</button>
    </div>
    <div id="shell">
        <div id="stageWrap">
            <div id="stage">
                <div class="reveal">
                    <div class="slides" id="slides"></div>
                </div>
            </div>
        </div>
        <aside id="notes">
            <h1>{{ $video->human_id }} · cue</h1>
            <div id="cue">Loading…</div>
            <div id="meta"></div>
        </aside>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/reveal.js@5.1.0/dist/reveal.js"></script>
    <script>
        window.PRESENTATIONS = window.PRESENTATIONS || {};
    </script>
    <script>{!! $manifest['js'] !!}</script>
    <script>
        (function () {
            const deckKey = @json($manifest['deck_key'] ?? null);
            const deck = (deckKey && PRESENTATIONS[deckKey]) || Object.values(PRESENTATIONS)[0];
            const cueEl = document.getElementById('cue');
            const metaEl = document.getElementById('meta');
            const slidesEl = document.getElementById('slides');
            const fsBtn = document.getElementById('fsBtn');

            if (!deck) {
                cueEl.textContent = 'Deck JavaScript did not register a presentation.';
                return;
            }

            if (typeof deck.slidesHtml === 'function') {
                slidesEl.innerHTML = deck.slidesHtml();
            } else if (typeof deck.slidesHtml === 'string') {
                slidesEl.innerHTML = deck.slidesHtml;
            } else {
                cueEl.textContent = 'This deck has no slidesHtml().';
                return;
            }

            const steps = Array.isArray(deck.steps) ? deck.steps : [];
            const reveal = new Reveal(document.querySelector('.reveal'), {
                embedded: true,
                hash: false,
                controls: true,
                progress: true,
                center: true,
                transition: 'fade',
                width: 1000,
                height: 1000,
                margin: 0,
            });

            function paintCue() {
                const idx = reveal.getIndices().h || 0;
                const step = steps[idx] || {};
                cueEl.textContent = step.cue || step.note || ('Slide ' + (idx + 1));
                metaEl.textContent = (idx + 1) + ' / ' + Math.max(steps.length, slidesEl.querySelectorAll('section').length);
            }

            reveal.initialize().then(function () {
                paintCue();
                reveal.on('slidechanged', paintCue);
            });

            fsBtn.addEventListener('click', function () {
                const node = document.getElementById('stage');
                if (!document.fullscreenElement) {
                    node.requestFullscreen?.();
                } else {
                    document.exitFullscreen?.();
                }
            });
        })();
    </script>
</body>
</html>
