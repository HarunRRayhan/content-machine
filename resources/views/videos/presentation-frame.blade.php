<!doctype html>
<html lang="en" @class(['dark' => ($theme ?? 'light') === 'dark'])>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/reveal.js@5.2.1/dist/reveal.css" integrity="sha384-tK61vs+AeByBmiQ/i6CLgp/PbaaoB/k7M4J5mlX70GVtBPGUk5avjbXhSwhsf3fS" crossorigin="anonymous">
    <style>
        :root {
            --font-bn: "Instrument Sans", "Noto Sans Bengali", "Hind Siliguri", ui-sans-serif, system-ui, sans-serif;
            --font-display: "Instrument Sans", ui-sans-serif, system-ui, sans-serif;
            --font-cue: "IBM Plex Mono", ui-monospace, Menlo, Consolas, monospace;
            --bg: #eef0f1;
            --bg2: #f6f7f8;
            --bg3: #e5e7e9;
            --raised: #ffffff;
            --ink: #1c1e20;
            --ink-soft: #52565a;
            --ink-faint: #8a8f94;
            --line: rgba(28, 30, 32, .12);
            --line-strong: rgba(28, 30, 32, .2);
            --accent: #1a7a4c;
            --accent-soft: #125c38;
            --amber: #9c6b16;
            --good: #3f8b52;
            --warn: #9c6b16;
            --bad: #c23a22;
            --info: #3d6ea5;
            --ready: #6b4fa0;
        }
        html.dark {
            --bg: #202427;
            --bg2: #282d31;
            --bg3: #31373b;
            --raised: #3a4146;
            --ink: #f0f2f3;
            --ink-soft: #c2c8cc;
            --ink-faint: #929ba1;
            --line: rgba(240, 242, 243, .1);
            --line-strong: rgba(240, 242, 243, .18);
            --accent: #72c79b;
            --accent-soft: #9be0b8;
            --amber: #e7b45d;
            --good: #86d69a;
            --warn: #e7b45d;
            --bad: #ed7761;
            --info: #83b4e1;
            --ready: #c0a9f0;
        }
        * { box-sizing: border-box; }
        html, body { width: 100%; height: 100%; margin: 0; overflow: hidden; }
        body { background: var(--bg); color: var(--ink); }
        .pres-stage { position: relative; width: 100%; height: 100%; min-height: 100vh;
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            overflow: hidden; container-type: size; }
        .pres-stage-reveal { display: block; }
        .pres-stage-reveal .reveal { width: 100%; height: 100%; }
        .pres-load-error { position: absolute; inset: 0; display: flex; align-items: center;
            justify-content: center; padding: 5cqw; color: #b91c1c; font: 15px system-ui, sans-serif;
            text-align: center; }
    </style>
</head>
<body>
    <div class="pres-stage" id="presStage">Loading...</div>

    <script src="https://cdn.jsdelivr.net/npm/reveal.js@5.2.1/dist/reveal.js" integrity="sha384-rZIIVPQD/+U9IL3I62KrBHuVtPgYv+xdXvER5RBiVQRszJEJfLKRrcrZwI7fhcHT" crossorigin="anonymous"></script>
    <script>
        (function () {
            const sources = {
                libCss: @json($manifest['lib_css'] ?? ''),
                deckCss: @json($manifest['css'] ?? ''),
                libJs: @json($manifest['lib_js'] ?? ''),
                deckJs: @json($manifest['js'] ?? ''),
            };
            const deckKeys = @json($deckKeys);
            const stageEl = document.getElementById('presStage');

            const styles = document.createElement('style');
            styles.textContent = (sources.libCss || '') + '\n' + (sources.deckCss || '');
            document.head.appendChild(styles);

            window.PRESENTATIONS = window.PRESENTATIONS || {};

            function execute(source) {
                if (!source) return;
                const script = document.createElement('script');
                script.textContent = source;
                document.body.appendChild(script);
            }

            execute(sources.libJs);
            execute(sources.deckJs);

            function notify(type, payload) {
                window.parent.postMessage({ source: 'cm-pres-frame', type: type, ...payload }, '*');
            }

            const deck = (Array.isArray(deckKeys) ? deckKeys : [])
                .map(function (key) { return window.PRESENTATIONS[key]; })
                .find(function (candidate) { return candidate && typeof candidate === 'object'; });

            if (!deck) {
                stageEl.textContent = 'Deck JavaScript did not register the requested presentation.';
                stageEl.className = 'pres-stage';
                notify('error', { message: 'Deck JavaScript did not register the requested presentation.' });
                return;
            }

            const steps = Array.isArray(deck.steps) ? deck.steps.map(function (step) {
                const value = step && typeof step === 'object' ? step : {};
                return {
                    cue: typeof value.cue === 'string' ? value.cue : '',
                    note: typeof value.note === 'string' ? value.note : '',
                    editable: value.editable !== false,
                    slide: Number.isInteger(value.slide) ? value.slide : null,
                    fragment: Number.isInteger(value.fragment) ? value.fragment : null,
                };
            }) : [];
            let stepStates = [];
            let currentStep = 0;
            let reveal = null;
            const isReveal = (deck.engine || typeof deck.slidesHtml === 'function') === 'reveal'
                || typeof deck.slidesHtml === 'function';

            function clampStep(step) {
                return Math.max(0, Math.min(Math.max(steps.length - 1, 0), step));
            }

            function stateKey(slide, fragment) {
                return slide + ':' + (fragment === null ? '' : fragment);
            }

            function visualStates() {
                return Array.from(stageEl.querySelectorAll('.slides > section')).flatMap(function (section, slide) {
                    const fragments = Array.from(section.querySelectorAll('.fragment'))
                        .map(function (fragment, index) {
                            const value = Number(fragment.getAttribute('data-fragment-index'));
                            return Number.isInteger(value) ? value : index;
                        });
                    const uniqueFragments = Array.from(new Set(fragments)).sort(function (a, b) { return a - b; });

                    return [{ slide: slide, fragment: null }].concat(
                        uniqueFragments.map(function (fragment) { return { slide: slide, fragment: fragment }; }),
                    );
                });
            }

            function resolveStepStates(states) {
                const hasExplicitMapping = steps.some(function (step) {
                    return step.slide !== null || step.fragment !== null;
                });
                const mapped = hasExplicitMapping
                    ? steps.map(function (step) {
                        if (!Number.isInteger(step.slide)) {
                            throw new Error('This deck has an incomplete Reveal step map.');
                        }

                        return { slide: step.slide, fragment: step.fragment };
                    })
                    : (states.length === steps.length ? states : null);

                if (!mapped) {
                    throw new Error('This deck has no unambiguous Reveal step map.');
                }
                if (mapped.length !== states.length) {
                    throw new Error('This deck has an incomplete Reveal step map.');
                }

                const available = new Set(states.map(function (state) {
                    return stateKey(state.slide, state.fragment);
                }));
                const seen = new Set();

                mapped.forEach(function (state) {
                    const key = stateKey(state.slide, state.fragment);
                    if (!available.has(key) || seen.has(key)) {
                        throw new Error('This deck has an invalid Reveal step map.');
                    }
                    seen.add(key);
                });

                return mapped;
            }

            function stepForState(slide, fragment) {
                return stepStates.findIndex(function (state) {
                    return state.slide === slide && state.fragment === fragment;
                });
            }

            function notifyState() {
                if (reveal) {
                    const indices = reveal.getIndices() || {};
                    const slide = Number.isInteger(indices.h) ? indices.h : 0;
                    const fragment = Number.isInteger(indices.f) && indices.f >= 0 ? indices.f : null;
                    const mappedStep = stepForState(slide, fragment);
                    if (mappedStep < 0) {
                        showError('Reveal reached a visual state without a script cue.');
                        return;
                    }
                    currentStep = mappedStep;
                    notify('state', {
                        step: currentStep,
                        slide: slide,
                        fragment: fragment,
                    });
                    return;
                }

                notify('state', { step: currentStep, slide: currentStep, fragment: null });
            }

            function showStageStep(step) {
                currentStep = clampStep(step);
                stageEl.className = 'pres-stage step-' + currentStep;
                stageEl.querySelectorAll('[data-from]').forEach(function (element) {
                    const from = +element.dataset.from;
                    const until = element.dataset.until != null ? +element.dataset.until : Infinity;
                    element.classList.toggle('on', currentStep >= from && currentStep <= until);
                });
                stageEl.querySelectorAll('[data-recede]').forEach(function (element) {
                    element.classList.toggle('recede', currentStep >= +element.dataset.recede);
                });
                notifyState();
            }

            function goToStep(step) {
                const target = clampStep(step);
                if (reveal) {
                    const visualStep = stepStates[target] || {};
                    reveal.slide(visualStep.slide ?? target, 0, visualStep.fragment ?? -1);
                    notifyState();
                    return;
                }
                showStageStep(target);
            }

            function toggleRevealKey(key) {
                if (key === 'ArrowRight' || key === ' ') { reveal.next(); return; }
                if (key === 'ArrowLeft') { reveal.prev(); return; }
                if (key === 'r' || key === 'R') { reveal.slide(0, 0, -1); return; }
                if (key === 'f' || key === 'F') {
                    window.parent.postMessage({ source: 'cm-pres-frame', type: 'fs' }, '*');
                }
            }

            function handleKey(key) {
                if (reveal) {
                    toggleRevealKey(key);
                    return;
                }
                if (key === 'ArrowRight' || key === ' ') { showStageStep(currentStep + 1); return; }
                if (key === 'ArrowLeft') { showStageStep(currentStep - 1); return; }
                if (key === 'r' || key === 'R') { showStageStep(0); return; }
                if (key === 'f' || key === 'F') {
                    window.parent.postMessage({ source: 'cm-pres-frame', type: 'fs' }, '*');
                }
            }

            function showError(message) {
                stageEl.textContent = message;
                stageEl.className = 'pres-stage';
                notify('error', { message: message });
            }

            if (isReveal) {
                if (typeof Reveal === 'undefined') {
                    showError('Reveal.js failed to load.');
                    return;
                }

                try {
                    const html = typeof deck.slidesHtml === 'function' ? deck.slidesHtml() : (deck.slidesHtml || '');
                    stageEl.className = 'pres-stage pres-stage-reveal';
                    stageEl.innerHTML = '<div class="reveal" id="presReveal"><div class="slides">' + html + '</div></div>';
                    stepStates = resolveStepStates(visualStates());
                    reveal = new Reveal(document.getElementById('presReveal'), {
                        embedded: true, keyboard: false, controls: false, progress: false, hash: false,
                        respondToHashChanges: false, postMessage: false,
                        width: 1000, height: 1000, margin: 0, minScale: 0.2, maxScale: 2,
                        transition: 'fade',
                    });
                    reveal.on('slidechanged', notifyState);
                    reveal.on('fragmentshown', notifyState);
                    reveal.on('fragmenthidden', notifyState);
                    reveal.initialize().then(function () {
                        notify('ready', { steps: steps.map(function (step, index) {
                            return { ...step, ...stepStates[index] };
                        }) });
                        notifyState();
                    }).catch(function () { showError('Reveal.js could not initialize this deck.'); });
                } catch (error) {
                    showError(error instanceof Error ? error.message : 'This deck could not be rendered.');
                    return;
                }
            } else if (typeof deck.stage === 'function') {
                try {
                    stageEl.innerHTML = deck.stage();
                    showStageStep(0);
                    notify('ready', { steps: steps });
                } catch (error) {
                    showError(error instanceof Error ? error.message : 'This deck could not be rendered.');
                }
            } else {
                showError('This deck has no slidesHtml() or stage().');
            }

            window.addEventListener('message', function (event) {
                if (event.source !== window.parent) return;
                const data = event.data;
                if (!data || data.source !== 'cm-pres-host') return;
                if (data.type === 'key' && typeof data.key === 'string') handleKey(data.key);
                if (data.type === 'go' && Number.isInteger(data.step)) goToStep(data.step);
                if (data.type === 'cue' && Number.isInteger(data.step) && steps[data.step]) {
                    steps[data.step].cue = typeof data.cue === 'string' ? data.cue : steps[data.step].cue;
                }
            });

            document.addEventListener('keydown', function (event) {
                if (![' ', 'ArrowRight', 'ArrowLeft', 'r', 'R', 'f', 'F'].includes(event.key)) return;
                event.preventDefault();
                handleKey(event.key);
            });
        })();
    </script>
</body>
</html>
