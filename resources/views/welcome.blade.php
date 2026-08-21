<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>{{ config('app.name') }} — self-hosted content pipeline</title>
        <meta name="description" content="An open-source pipeline that turns a stray thought into a scheduled post: capture, draft, schedule, publish — self-hosted, no subscription.">

        <link rel="icon" href="/favicon.ico" sizes="any">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">

        @fonts(['big-shoulders-stencil-display', 'big-shoulders', 'ibm-plex-mono'])
        @vite(['resources/css/marketing.css'])
    </head>
    <body class="cm-marketing font-mono-cm min-h-screen antialiased">
        <div class="mx-auto flex min-h-screen max-w-5xl flex-col px-6">

            <header class="flex items-center justify-between border-b border-cm-border py-6">
                <a href="/" class="flex items-center gap-3">
                    <svg viewBox="0 0 40 40" class="h-8 w-8 rounded-md" xmlns="http://www.w3.org/2000/svg">
                        <rect width="40" height="40" rx="9" fill="#171310"/>
                        <polygon points="8,6 22,20 8,34 14,34 28,20 14,6" fill="#F2600C"/>
                        <polygon points="20,11 32,20 20,29 25,29 37,20 25,11" fill="#F2600C" opacity="0.42"/>
                    </svg>
                    <span class="font-heading text-lg font-semibold tracking-wide text-cm-fg uppercase">
                        {{ config('app.name') }}
                    </span>
                </a>

                <nav class="flex items-center gap-5 text-sm">
                    <a
                        href="https://github.com/HarunRRayhan/content-machine"
                        class="flex items-center gap-2 text-cm-fg-muted transition hover:text-cm-fg"
                    >
                        <svg viewBox="0 0 16 16" class="h-4 w-4" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                            <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                        </svg>
                        GitHub
                    </a>
                    <a
                        href="{{ route('login') }}"
                        class="rounded-sm border border-cm-border px-4 py-1.5 text-cm-fg transition hover:border-cm-accent hover:text-cm-accent"
                    >
                        Log in
                    </a>
                </nav>
            </header>

            <main class="flex-1">

                <section class="cm-rise py-20 sm:py-28">
                    <p class="mb-4 font-heading text-sm font-semibold tracking-[0.3em] text-cm-accent uppercase">
                        Open source · self-hosted
                    </p>
                    <h1 class="font-display text-6xl leading-[0.95] font-bold tracking-tight text-cm-fg uppercase sm:text-8xl">
                        Ideas in.<br>Posts out.
                    </h1>
                    <p class="mt-8 max-w-xl text-base leading-relaxed text-cm-fg-muted sm:text-lg">
                        {{ config('app.name') }} is a content pipeline for capturing a stray
                        thought and turning it into a scheduled post — no third-party SaaS
                        holding your drafts, no per-seat subscription. Run it on your own
                        server.
                    </p>
                    <div class="mt-10 flex flex-wrap items-center gap-4">
                        <a
                            href="https://github.com/HarunRRayhan/content-machine"
                            class="flex items-center gap-2 rounded-sm bg-cm-accent px-6 py-3 text-sm font-semibold text-cm-bg transition hover:brightness-110"
                        >
                            <svg viewBox="0 0 16 16" class="h-4 w-4" fill="currentColor" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                                <path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/>
                            </svg>
                            Star on GitHub
                        </a>
                        <a
                            href="{{ route('login') }}"
                            class="rounded-sm border border-cm-border px-6 py-3 text-sm text-cm-fg transition hover:border-cm-accent hover:text-cm-accent"
                        >
                            Log in →
                        </a>
                    </div>
                </section>

                <section class="border-t border-cm-border py-16 sm:py-20">
                    <h2 class="font-heading text-sm font-semibold tracking-[0.3em] text-cm-fg-muted uppercase">
                        How it moves through the line
                    </h2>

                    <div class="mt-10 grid gap-px overflow-hidden rounded-sm border border-cm-border bg-cm-border sm:grid-cols-4">
                        @foreach ([
                            ['01', 'Capture', 'A voice note, a forwarded link, a typed thought — from a phone, a bot, anywhere.'],
                            ['02', 'Draft', 'Shaped into a post or a script, per platform, in your own voice.'],
                            ['03', 'Schedule', 'Queued for the right time, across every platform you publish to.'],
                            ['04', 'Publish', 'Goes out on its own. You get the credit, not the busywork.'],
                        ] as $i => [$num, $title, $body])
                            <div
                                class="cm-rise bg-cm-bg-raised p-6"
                                style="animation-delay: {{ 120 + $i * 90 }}ms"
                            >
                                <span class="font-display text-3xl text-cm-accent-dim">{{ $num }}</span>
                                <h3 class="mt-2 font-heading text-lg font-semibold tracking-wide text-cm-fg uppercase">
                                    {{ $title }}
                                </h3>
                                <p class="mt-2 text-sm leading-relaxed text-cm-fg-muted">
                                    {{ $body }}
                                </p>
                            </div>
                        @endforeach
                    </div>
                </section>

                <section class="border-t border-cm-border py-16 sm:py-20">
                    <div class="cm-hazard-rule mb-8 h-1.5 w-24"></div>
                    <h2 class="font-display text-3xl font-bold tracking-tight text-cm-fg uppercase sm:text-4xl">
                        Building in public
                    </h2>
                    <p class="mt-4 max-w-xl text-sm leading-relaxed text-cm-fg-muted">
                        This is early — pre-alpha, under active development. The code is
                        public from day one; features ship in small, working slices rather
                        than all at once.
                    </p>
                    <dl class="mt-8 grid grid-cols-2 gap-x-8 gap-y-4 text-sm sm:grid-cols-4">
                        <div>
                            <dt class="text-cm-fg-muted uppercase">Stack</dt>
                            <dd class="mt-1 text-cm-fg">Laravel 13</dd>
                        </div>
                        <div>
                            <dt class="text-cm-fg-muted uppercase">Frontend</dt>
                            <dd class="mt-1 text-cm-fg">Inertia · React</dd>
                        </div>
                        <div>
                            <dt class="text-cm-fg-muted uppercase">Database</dt>
                            <dd class="mt-1 text-cm-fg">Postgres</dd>
                        </div>
                        <div>
                            <dt class="text-cm-fg-muted uppercase">License</dt>
                            <dd class="mt-1 text-cm-fg">MIT</dd>
                        </div>
                    </dl>
                </section>

            </main>

            <footer class="flex flex-col gap-2 border-t border-cm-border py-8 text-xs text-cm-fg-muted sm:flex-row sm:items-center sm:justify-between">
                <span>&copy; {{ now()->year }} {{ config('app.name') }}. MIT licensed.</span>
                <a
                    href="https://github.com/HarunRRayhan/content-machine"
                    class="transition hover:text-cm-fg"
                >
                    github.com/HarunRRayhan/content-machine
                </a>
            </footer>

        </div>
    </body>
</html>
