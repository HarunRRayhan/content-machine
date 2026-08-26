<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $video->human_id }} · Presentation</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js@5.2.1/dist/reveal.css">
    <style>
        :root {
            --font-bn: "Kohinoor Bangla", "Noto Sans Bengali", "Hind Siliguri", system-ui, sans-serif;
            --font-display: ui-serif, Georgia, "Times New Roman", serif;
            --font-cue: ui-monospace, Menlo, Consolas, monospace;
            --bg: #efe7d8;
            --bg2: #faf5ea;
            --bg3: #f2ebdc;
            --raised: #fffdf8;
            --ink: #2a2119;
            --ink-soft: #5f5240;
            --ink-faint: #94836c;
            --line: rgba(42, 33, 25, 0.12);
            --line-strong: rgba(42, 33, 25, 0.2);
            --accent: #c23a22;
            --accent-soft: #a13018;
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; background: var(--bg); color: var(--ink); font-family: var(--font-bn); }
        body.embed { background: #fff; }
        a.back {
            position: fixed; top: 12px; left: 12px; z-index: 50;
            background: rgba(255,255,255,.92); color: var(--ink);
            border: 1px solid var(--line-strong); border-radius: 8px;
            padding: 8px 12px; text-decoration: none; font-size: 13px;
        }
        .pres-shell{display:flex; align-items:flex-start; gap:28px; flex-wrap:nowrap;
            width:100vw; position:relative; left:50%; margin-left:-50vw; box-sizing:border-box;
            padding:4px clamp(14px,3vw,30px) 40px}
        body.embed .pres-shell{width:100%; left:0; margin-left:0; padding:0; min-height:100vh}
        .pres-left-col{flex:none; display:flex}
        .pres-left{flex:none}
        .pres-fs-btn{font-size:14px; padding:8px 16px; margin-bottom:16px; border:1px solid var(--line-strong);
            border-radius:999px; background:var(--bg2); cursor:pointer}
        .pres-stage{position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:2.2cqh; padding:4cqh 5cqw; text-align:center}
        .pres-stage-reveal{position:absolute; inset:0; display:block; padding:0; gap:0}
        .pres-stage-reveal .reveal{width:100%; height:100%}
        .pres-load-error{position:absolute; inset:0; display:flex; align-items:center; justify-content:center;
            text-align:center; padding:5cqw; color:#b91c1c; font-size:15px}
        .pres-stage > [data-from]:not(.on){position:absolute !important; visibility:hidden; pointer-events:none}
        .pres-left, #presLeft{position:relative; height:70vh; width:70vh;
            --bg:#eef0f1; --bg2:#f6f7f8; --bg3:#e5e7e9; --raised:#ffffff;
            --ink:#1c1e20; --ink-soft:#52565a; --ink-faint:#8a8f94;
            --line:rgba(28,30,32,.12); --line-strong:rgba(28,30,32,.2);
            --accent:#1a7a4c; --accent-soft:#125c38; --amber:#9c6b16;
            --good:#3f8b52; --warn:#9c6b16; --bad:#c23a22; --info:#3d6ea5; --ready:#6b4fa0;
            border-radius:18px; background:#ffffff; overflow:hidden; container-type:size}
        #presShell.is-fullscreen{display:flex; align-items:center; justify-content:center; gap:36px; width:100vw; height:100vh;
            background:#ffffff; padding:3vh 4vw; box-sizing:border-box; position:static; left:auto; margin-left:0}
        #presShell.is-fullscreen .pres-left, #presShell.is-fullscreen #presLeft{height:calc(100vh - 76px); width:calc(100vh - 76px)}
        #presShell.is-fullscreen .pres-notes{max-width:640px; font-size:1.15em}
        .pres-stepno{font-family:monospace; font-size:12px; color:var(--ink-faint); text-align:center; margin:0 0 8px}
        .pres-dots{display:flex; justify-content:center; gap:7px; margin:0 0 12px}
        .pres-dot{width:7px; height:7px; border-radius:50%; background:var(--line-strong)}
        .pres-dot.active{background:var(--accent)}
        .pres-gap{align-self:stretch; width:2px; flex:none;
            background:repeating-linear-gradient(to bottom, var(--line-strong) 0 6px, transparent 6px 12px)}
        .pres-notes{flex:1 1 260px; min-width:240px; max-width:520px}
        body.embed .pres-notes{display:none}
        body.embed .pres-gap{display:none}
        .pres-cue{font-family:var(--font-bn); font-size:26px; font-weight:600; line-height:1.45; color:var(--ink);
            padding:18px; border:1px solid var(--line-strong); border-radius:14px; background:var(--bg2); margin-bottom:12px}
        .pres-keys{font-family:var(--font-cue); font-size:11px; color:var(--ink-faint); margin-bottom:16px}
        {!! $manifest['lib_css'] ?? '' !!}
        {!! $manifest['css'] ?? '' !!}
    </style>
</head>
<body @class(['embed' => $embed])>
    @unless($embed)
        <a class="back" href="{{ route('dashboard.videos.show', $video) }}">← Back</a>
    @endunless

    <div class="pres-shell" id="presShell">
        <div class="pres-left-col">
            <div class="pres-left" id="presLeft">
                <div class="pres-stage" id="presStage">Loading…</div>
            </div>
        </div>
        <div class="pres-gap"></div>
        <div class="pres-notes">
            <div class="pres-stepno" id="presStepNo"></div>
            <div class="pres-dots" id="presDots"></div>
            <div class="pres-cue" id="presCue"></div>
            <div class="pres-keys">Space / → next · ← back · R restart · F fullscreen</div>
            <button type="button" class="pres-fs-btn" id="presFsBtn">⛶ <span id="presFsLbl">Fullscreen</span></button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/reveal.js@5.2.1/dist/reveal.js"></script>
    <script>
        window.PRESENTATIONS = window.PRESENTATIONS || {};
    </script>
    <script>{!! $manifest['lib_js'] ?? '' !!}</script>
    <script>{!! $manifest['js'] ?? '' !!}</script>
    <script>
        (function () {
            const deckKey = @json($manifest['deck_key'] ?? null);
            const engineHint = @json($manifest['engine'] ?? null);
            const embed = @json($embed);
            const deck = (deckKey && window.PRESENTATIONS[deckKey]) || Object.values(window.PRESENTATIONS)[0];
            const stageEl = document.getElementById('presStage');
            const cueEl = document.getElementById('presCue');
            const stepNoEl = document.getElementById('presStepNo');
            const dotsEl = document.getElementById('presDots');
            const shell = document.getElementById('presShell');
            const fsBtn = document.getElementById('presFsBtn');
            const fsLbl = document.getElementById('presFsLbl');

            if (!deck) {
                stageEl.innerHTML = '<div class="pres-load-error">Deck JavaScript did not register a presentation.</div>';
                cueEl.textContent = 'Deck missing.';
                return;
            }

            const isReveal = (deck.engine || engineHint) === 'reveal' || typeof deck.slidesHtml === 'function';
            let presStep = 0;
            let presRevealInstance = null;

            if (isReveal) {
                const html = typeof deck.slidesHtml === 'function' ? deck.slidesHtml() : (deck.slidesHtml || '');
                stageEl.className = 'pres-stage pres-stage-reveal';
                stageEl.innerHTML = '<div class="reveal" id="presReveal"><div class="slides">' + html + '</div></div>';
                if (typeof Reveal === 'undefined') {
                    stageEl.innerHTML = '<div class="pres-load-error">Reveal.js failed to load.</div>';
                    return;
                }
                presRevealInstance = new Reveal(document.getElementById('presReveal'), {
                    embedded: true, keyboard: false, controls: false, progress: false, hash: false,
                    respondToHashChanges: false, postMessage: false,
                    width: 1000, height: 1000, margin: 0, minScale: 0.2, maxScale: 2,
                    transition: 'fade'
                });
            } else if (typeof deck.stage === 'function') {
                stageEl.className = 'pres-stage step-0';
                stageEl.innerHTML = deck.stage();
            } else {
                stageEl.innerHTML = '<div class="pres-load-error">This deck has no slidesHtml() or stage().</div>';
                return;
            }

            const steps = Array.isArray(deck.steps) ? deck.steps : [];
            dotsEl.innerHTML = steps.map(function (_, i) {
                return '<span class="pres-dot" data-i="' + i + '"></span>';
            }).join('');

            function updateChrome(n) {
                presStep = Math.max(0, Math.min(Math.max(steps.length - 1, 0), n));
                const step = steps[presStep] || {};
                cueEl.textContent = step.cue || step.note || ('Slide ' + (presStep + 1));
                stepNoEl.textContent = 'Step ' + presStep + ' / ' + Math.max(steps.length - 1, 0);
                dotsEl.querySelectorAll('.pres-dot').forEach(function (dot, i) {
                    dot.classList.toggle('active', i === presStep);
                });
            }

            function showPresStep(n) {
                if (isReveal && presRevealInstance) {
                    const target = Math.max(0, Math.min(steps.length - 1, n));
                    const delta = target - presStep;
                    if (delta > 0) { for (let i = 0; i < delta; i++) presRevealInstance.next(); }
                    else if (delta < 0) { for (let i = 0; i < -delta; i++) presRevealInstance.prev(); }
                    else if (target === 0) { presRevealInstance.slide(0, 0, -1); }
                    updateChrome(target);
                    return;
                }
                presStep = Math.max(0, Math.min(steps.length - 1, n));
                stageEl.className = 'pres-stage step-' + presStep;
                stageEl.querySelectorAll('[data-from]').forEach(function (el) {
                    const from = +el.dataset.from;
                    const until = el.dataset.until != null ? +el.dataset.until : Infinity;
                    el.classList.toggle('on', presStep >= from && presStep <= until);
                });
                stageEl.querySelectorAll('[data-recede]').forEach(function (el) {
                    el.classList.toggle('recede', presStep >= +el.dataset.recede);
                });
                updateChrome(presStep);
            }

            function sizePresLeft() {
                const left = document.getElementById('presLeft');
                if (!left) return;
                const isFs = shell.classList.contains('is-fullscreen');
                const side = isFs
                    ? Math.max(260, window.innerHeight - 76)
                    : Math.max(260, Math.min(window.innerHeight - left.getBoundingClientRect().top - 16, window.innerWidth - (embed ? 40 : 360)));
                left.style.height = side + 'px';
                left.style.width = side + 'px';
                if (presRevealInstance) presRevealInstance.layout();
            }

            function toggleFullscreen() {
                if (!document.fullscreenElement) shell.requestFullscreen?.();
                else document.exitFullscreen?.();
            }

            document.addEventListener('fullscreenchange', function () {
                const isFs = document.fullscreenElement === shell;
                shell.classList.toggle('is-fullscreen', isFs);
                if (fsLbl) fsLbl.textContent = isFs ? 'Exit fullscreen' : 'Fullscreen';
                sizePresLeft();
                requestAnimationFrame(sizePresLeft);
            });

            dotsEl.querySelectorAll('.pres-dot').forEach(function (dot) {
                dot.addEventListener('click', function () { showPresStep(+dot.dataset.i); });
            });

            if (fsBtn) fsBtn.addEventListener('click', toggleFullscreen);

            document.addEventListener('keydown', function (e) {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
                if (e.key === 'ArrowRight' || e.key === ' ') { e.preventDefault(); showPresStep(presStep + 1); }
                if (e.key === 'ArrowLeft') { e.preventDefault(); showPresStep(presStep - 1); }
                if (e.key === 'r' || e.key === 'R') { e.preventDefault(); showPresStep(0); }
                if (e.key === 'f' || e.key === 'F') { e.preventDefault(); toggleFullscreen(); }
            });

            window.addEventListener('resize', sizePresLeft);

            if (isReveal && presRevealInstance) {
                presRevealInstance.initialize().then(function () {
                    presRevealInstance.on('slidechanged', function () {
                        updateChrome(presRevealInstance.getIndices().h || 0);
                    });
                    updateChrome(0);
                    sizePresLeft();
                });
            } else {
                showPresStep(0);
                sizePresLeft();
            }
        })();
    </script>
</body>
</html>
