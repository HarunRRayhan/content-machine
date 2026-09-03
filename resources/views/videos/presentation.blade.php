<!DOCTYPE html>
<html lang="en" @class(['dark' => ($theme ?? 'light') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $video->human_id }} · Presentation</title>
    <link rel="icon" href="/favicon.svg?v=3" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico?v=3" sizes="32x32">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png?v=3">
    <style>
        :root {
            --font-bn: "Instrument Sans", "Noto Sans Bengali", "Hind Siliguri", ui-sans-serif, system-ui, sans-serif;
            --font-display: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            --font-cue: "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
            --cm-bg: oklch(1 0 0);
            --cm-fg: oklch(0.145 0 0);
            --cm-muted: oklch(0.97 0 0);
            --cm-muted-fg: oklch(0.556 0 0);
            --cm-border: oklch(0.922 0 0);
            --cm-primary: oklch(0.205 0 0);
            --bg: var(--cm-bg);
            --bg2: var(--cm-muted);
            --ink: var(--cm-fg);
            --ink-soft: var(--cm-muted-fg);
            --ink-faint: var(--cm-muted-fg);
            --line: var(--cm-border);
            --line-strong: color-mix(in oklch, var(--cm-fg) 20%, transparent);
            --accent: var(--cm-primary);
        }
        html.dark {
            --cm-bg: oklch(0.145 0 0);
            --cm-fg: oklch(0.985 0 0);
            --cm-muted: oklch(0.269 0 0);
            --cm-muted-fg: oklch(0.708 0 0);
            --cm-border: oklch(0.269 0 0);
            --cm-primary: oklch(0.985 0 0);
        }
        * { box-sizing: border-box; }
        html, body { margin: 0; min-height: 100%; background: var(--cm-bg); color: var(--cm-fg); font-family: var(--font-bn); }
        a.back {
            position: fixed; top: 12px; left: 12px; z-index: 50;
            background: var(--cm-muted); color: var(--cm-fg);
            border: 1px solid var(--cm-border); border-radius: 8px;
            padding: 8px 12px; text-decoration: none; font-size: 13px;
        }
        .pres-shell{display:flex; align-items:flex-start; gap:28px; flex-wrap:nowrap;
            width:100vw; position:relative; left:50%; margin-left:-50vw; box-sizing:border-box;
            padding:4px clamp(14px,3vw,30px) 40px}
        body.embed .pres-shell{width:100%; left:0; margin-left:0; padding:12px 16px; min-height:100vh}
        .pres-left-col{flex:none; display:flex}
        .pres-left{flex:none}
        .pres-fs-btn{font-size:14px; padding:8px 16px; margin-bottom:16px; border:1px solid var(--cm-border);
            border-radius:999px; background:var(--cm-muted); color: var(--cm-fg); cursor:pointer}
        .pres-stage{position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center;
            gap:2.2cqh; padding:4cqh 5cqw; text-align:center}
        .pres-stage-reveal{position:absolute; inset:0; display:block; padding:0; gap:0}
        .pres-stage-reveal .reveal{width:100%; height:100%}
        .pres-frame{display:block; width:100%; height:100%; border:0; background:transparent}
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
            background:#ffffff; padding:3vh 4vw; box-sizing:border-box; position:static; left:auto; margin-left:0;
            color:#1c1e20}
        #presShell.is-fullscreen .pres-left, #presShell.is-fullscreen #presLeft{height:calc(100vh - 76px); width:calc(100vh - 76px)}
        #presShell.is-fullscreen .pres-notes{max-width:640px; font-size:1.15em; color:#1c1e20}
        #presShell.is-fullscreen .pres-cue{background:#f6f7f8; border-color:rgba(28,30,32,.2); color:#1c1e20}
        #presShell.is-fullscreen .pres-fs-btn{background:#f6f7f8; color:#1c1e20; border-color:rgba(28,30,32,.2)}
        .pres-vidno{font-family:var(--font-cue); font-size:15px; font-weight:800; color:var(--cm-fg);
            text-align:center; margin:0 0 2px; letter-spacing:.02em}
        .pres-stepno{font-family:monospace; font-size:12px; color:var(--cm-muted-fg); text-align:center; margin:0 0 8px}
        .pres-dots{display:flex; justify-content:center; gap:7px; margin:0 0 12px}
        .pres-dot{width:7px; height:7px; border-radius:50%; background:var(--cm-border)}
        .pres-dot.active{background:var(--cm-primary)}
        .pres-gap{align-self:stretch; width:2px; flex:none;
            background:repeating-linear-gradient(to bottom, var(--cm-border) 0 6px, transparent 6px 12px)}
        .pres-notes{flex:1 1 260px; min-width:240px; max-width:520px}
        .pres-cue{font-family:var(--font-bn); font-size:26px; font-weight:600; line-height:1.45; color:var(--cm-fg);
            padding:18px; border:1px solid var(--cm-border); border-radius:14px; background:var(--cm-muted); margin:0}
        .pres-cue-row{display:flex; align-items:stretch; gap:8px; margin-bottom:12px}
        .pres-cue-row .pres-cue{flex:1}
        .pres-cue-editbtn{width:48px; border:1px solid var(--cm-border); border-radius:12px; background:var(--cm-muted); color:var(--cm-fg); cursor:pointer; font-size:20px}
        .pres-cue-editor[hidden], .pres-cue-row[hidden]{display:none}
        .pres-cue-editor textarea{display:block; width:100%; min-height:120px; resize:vertical; font:600 22px/1.45 var(--font-bn); color:var(--cm-fg); background:var(--cm-muted); border:1px solid var(--cm-border); border-radius:12px; padding:14px; margin-bottom:8px}
        .pres-cue-actions{display:flex; gap:8px; justify-content:flex-end}
        .pres-cue-error{color:#b91c1c; font-size:13px; margin:8px 0}
        .pres-keys{font-family:var(--font-cue); font-size:11px; color:var(--cm-muted-fg); margin-bottom:16px}
    </style>
</head>
<body @class(['embed' => $embed])>
    @unless($embed)
        <a class="back" href="{{ route('videos.show', $video) }}">← Back</a>
    @endunless

    <div class="pres-shell" id="presShell">
        <div class="pres-left-col">
            <div class="pres-left" id="presLeft">
                <div class="pres-stage" id="presStage">
                    <iframe
                        class="pres-frame"
                        id="presFrame"
                        src="{{ route('videos.presentation.frame', ['video' => $video, 'theme' => $theme]) }}"
                        title="{{ $video->title }} presentation"
                        sandbox="allow-scripts"
                        allow="fullscreen"
                        allowfullscreen
                    ></iframe>
                </div>
            </div>
        </div>
        <div class="pres-gap"></div>
        <div class="pres-notes" id="presNotes">
            <div class="pres-vidno" id="presVidNo">{{ $video->human_id }}</div>
            <div class="pres-stepno" id="presStepNo"></div>
            <div class="pres-dots" id="presDots"></div>
            <div class="pres-cue-row" id="presCueRow">
                <div class="pres-cue" id="presCue"></div>
                <button type="button" class="pres-cue-editbtn" id="presCueEditBtn" title="Edit this script line" aria-label="Edit this script line">✎</button>
            </div>
            <div class="pres-cue-editor" id="presCueEditor" hidden>
                <textarea id="presCueInput" rows="3" aria-label="Script line"></textarea>
                <div class="pres-cue-actions">
                    <button type="button" class="pres-fs-btn" id="presCueCancelBtn">Cancel</button>
                    <button type="button" class="pres-fs-btn" id="presCueSaveBtn">Save</button>
                </div>
                <div class="pres-cue-error" id="presCueError" role="alert" hidden></div>
            </div>
            <div class="pres-keys">Space / → next · ← back · R restart · F fullscreen</div>
            <button type="button" class="pres-fs-btn" id="presFsBtn">⛶ <span id="presFsLbl">Fullscreen</span></button>
        </div>
    </div>

    <script>
        (function () {
            const frameEl = document.getElementById('presFrame');
            const cueEl = document.getElementById('presCue');
            const stepNoEl = document.getElementById('presStepNo');
            const dotsEl = document.getElementById('presDots');
            const shell = document.getElementById('presShell');
            const fsBtn = document.getElementById('presFsBtn');
            const fsLbl = document.getElementById('presFsLbl');
            const cueRow = document.getElementById('presCueRow');
            const cueEditor = document.getElementById('presCueEditor');
            const cueInput = document.getElementById('presCueInput');
            const cueEditBtn = document.getElementById('presCueEditBtn');
            const cueCancelBtn = document.getElementById('presCueCancelBtn');
            const cueSaveBtn = document.getElementById('presCueSaveBtn');
            const cueError = document.getElementById('presCueError');
            const cueUpdateUrl = @json(route('videos.presentation.cue', $video));
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            let steps = [];
            let presStep = 0;

            function postFrame(payload) {
                if (!frameEl?.contentWindow) return;
                frameEl.contentWindow.postMessage({ source: 'cm-pres-host', ...payload }, '*');
            }

            function clampStep(n) {
                return Math.max(0, Math.min(Math.max(steps.length - 1, 0), n));
            }

            function setSteps(rawSteps) {
                if (!Array.isArray(rawSteps)) return;
                steps = rawSteps.slice(0, 200).map(function (step) {
                    const value = step && typeof step === 'object' ? step : {};
                    return {
                        cue: typeof value.cue === 'string' ? value.cue.slice(0, 5000) : '',
                        note: typeof value.note === 'string' ? value.note.slice(0, 5000) : '',
                        editable: value.editable !== false,
                        slide: Number.isInteger(value.slide) ? value.slide : undefined,
                    };
                });
                dotsEl.innerHTML = steps.map(function (_, i) {
                    return '<span class="pres-dot" data-i="' + i + '"></span>';
                }).join('');
                dotsEl.querySelectorAll('.pres-dot').forEach(function (dot) {
                    dot.addEventListener('click', function () { showPresStep(+dot.dataset.i); });
                });
            }

            function updateChrome(n) {
                presStep = clampStep(n);
                const step = steps[presStep] || {};
                cueEl.textContent = step.cue || step.note || ('Slide ' + (presStep + 1));
                if (cueEditBtn) {
                    cueEditBtn.hidden = step.editable === false || typeof step.cue !== 'string' || step.cue.trim() === '';
                }
                stepNoEl.textContent = steps.length
                    ? 'Step ' + presStep + ' / ' + Math.max(steps.length - 1, 0)
                    : 'Deck has no script cues';
                dotsEl.querySelectorAll('.pres-dot').forEach(function (dot, i) {
                    dot.classList.toggle('active', i === presStep);
                });
            }

            function exitCueEdit() {
                if (cueRow) cueRow.hidden = false;
                if (cueEditor) cueEditor.hidden = true;
                if (cueError) {
                    cueError.hidden = true;
                    cueError.textContent = '';
                }
            }

            function enterCueEdit() {
                if (!cueInput || !cueRow || !cueEditor) return;
                const step = steps[presStep] || {};
                if (typeof step.cue !== 'string' || step.cue.trim() === '') return;
                cueInput.value = step.cue;
                cueRow.hidden = true;
                cueEditor.hidden = false;
                cueInput.focus();
                cueInput.select();
            }

            async function saveCueEdit() {
                if (!cueInput || !cueSaveBtn) return;
                const cue = cueInput.value.trim();
                if (!cue || /[\r\n]/.test(cue)) {
                    if (cueError) {
                        cueError.textContent = 'Enter one non-empty line.';
                        cueError.hidden = false;
                    }
                    return;
                }
                cueSaveBtn.disabled = true;
                cueSaveBtn.textContent = 'Saving…';
                try {
                    const response = await fetch(cueUpdateUrl, {
                        method: 'PATCH',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({
                            step: presStep,
                            current_cue: (steps[presStep] || {}).cue || '',
                            cue,
                        }),
                    });
                    const result = await response.json();
                    if (!response.ok || !result.ok) {
                        throw new Error(result.error || 'Save failed.');
                    }
                    if (steps[presStep]) steps[presStep].cue = result.cue || cue;
                    postFrame({ type: 'cue', step: presStep, cue: result.cue || cue });
                    updateChrome(presStep);
                    exitCueEdit();
                } catch (error) {
                    if (cueError) {
                        cueError.textContent = error instanceof Error ? error.message : 'Save failed.';
                        cueError.hidden = false;
                    }
                } finally {
                    cueSaveBtn.disabled = false;
                    cueSaveBtn.textContent = 'Save';
                }
            }

            function showPresStep(n) {
                exitCueEdit();
                if (!steps.length) return;
                const target = clampStep(n);
                updateChrome(target);
                postFrame({ type: 'go', step: target });
            }

            function sizePresLeft() {
                const left = document.getElementById('presLeft');
                if (!left) return;
                const isFs = shell.classList.contains('is-fullscreen');
                const side = isFs
                    ? Math.max(260, window.innerHeight - 76)
                    : Math.max(260, Math.min(window.innerHeight - left.getBoundingClientRect().top - 16, window.innerWidth - 360));
                left.style.height = side + 'px';
                left.style.width = side + 'px';
            }

            function applyFs(isFs) {
                shell.classList.toggle('is-fullscreen', isFs);
                if (fsLbl) fsLbl.textContent = isFs ? 'Exit fullscreen' : 'Fullscreen';
                sizePresLeft();
                requestAnimationFrame(sizePresLeft);
            }

            function toggleFullscreen() {
                if (!document.fullscreenElement) {
                    shell.requestFullscreen?.().catch(function () {});
                } else {
                    document.exitFullscreen?.().catch(function () {});
                }
            }

            function handleKey(key) {
                if (key === 'ArrowRight' || key === ' ' || key === 'ArrowLeft' || key === 'r' || key === 'R') {
                    postFrame({ type: 'key', key: key });
                    return;
                }
                if (key === 'f' || key === 'F') { toggleFullscreen(); }
            }

            document.addEventListener('fullscreenchange', function () {
                applyFs(document.fullscreenElement === shell);
            });

            // The iframe is sandboxed without allow-same-origin, so its
            // recipient origin is opaque and the parent must use '*'. Validate
            // the sender origin here instead: an embedded document should only
            // accept commands from the origin that loaded it.
            var parentOrigin = null;
            try {
                parentOrigin = document.referrer ? new URL(document.referrer).origin : null;
            } catch (e) {
                parentOrigin = null;
            }

            window.addEventListener('message', function (event) {
                if (event.source !== window.parent) return;
                if (parentOrigin !== null && event.origin !== parentOrigin) return;
                if (parentOrigin === null && event.origin !== 'null') return;
                const data = event.data;
                if (!data) return;

                if (event.source === frameEl?.contentWindow && event.origin === 'null' && data.source === 'cm-pres-frame') {
                    if (data.type === 'ready') {
                        setSteps(data.steps);
                        updateChrome(0);
                    }
                    if (data.type === 'state' && Number.isInteger(data.step)) updateChrome(data.step);
                    if (data.type === 'error') cueEl.textContent = data.message || 'Deck failed to load.';
                    if (data.type === 'fs') toggleFullscreen();
                    return;
                }

                if (event.source !== window.parent || window.parent === window || event.origin !== window.location.origin) return;
                if (data.source !== 'cm-pres') return;
                if (data.type === 'key' && typeof data.key === 'string') handleKey(data.key);
                if (data.type === 'fs') applyFs(!!data.on);
            });

            if (fsBtn) fsBtn.addEventListener('click', toggleFullscreen);
            if (cueEditBtn) cueEditBtn.addEventListener('click', enterCueEdit);
            if (cueCancelBtn) cueCancelBtn.addEventListener('click', exitCueEdit);
            if (cueSaveBtn) cueSaveBtn.addEventListener('click', saveCueEdit);

            document.addEventListener('keydown', function (e) {
                if (e.target && (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA')) return;
                if (e.key === 'ArrowRight' || e.key === ' ' || e.key === 'ArrowLeft' || e.key === 'r' || e.key === 'R' || e.key === 'f' || e.key === 'F') {
                    e.preventDefault();
                    handleKey(e.key);
                }
            });

            window.addEventListener('resize', sizePresLeft);

            if (frameEl) frameEl.addEventListener('load', function () { postFrame({ type: 'go', step: presStep }); });
            updateChrome(0);
            sizePresLeft();
        })();
    </script>
</body>
</html>
