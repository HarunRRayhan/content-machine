import { useMemo, useState } from 'react';

type ScriptBlock = {
    lang: string;
    body: string;
};

function escapeHtml(text: string): string {
    return text
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function renderLineBody(line: string): string {
    return escapeHtml(line).replace(
        /\[([^\]]+)\]/g,
        '<span class="cue">$1</span>',
    );
}

export function renderScriptBodyHtml(text: string): string {
    const out: string[] = [];
    let lineNumber = 0;

    for (const raw of (text || '').split('\n')) {
        const line = raw.replace(/\s+$/, '');

        if (line.trim() === '') {
            out.push('<div class="sp"></div>');
            continue;
        }

        const trimmed = line.trim();
        const section = trimmed.match(/^\[(.+)\]$/);

        if (
            section &&
            /HOOK|BODY|REVEAL|CLOSING|CTA/i.test(section[1])
        ) {
            out.push(
                `<div class="sec"><span>${escapeHtml(section[1])}</span></div>`,
            );
            continue;
        }

        lineNumber += 1;
        out.push(
            `<p class="ln"><span class="lnno">${lineNumber}</span><span class="lntext">${renderLineBody(line)}</span></p>`,
        );
    }

    return out.join('');
}

type Props = {
    scripts: ScriptBlock[];
    videoNumber: number;
    storageKey: string;
};

export default function ScriptPanel({
    scripts,
    videoNumber,
    storageKey,
}: Props) {
    const many = scripts.length > 1;

    const initialIndex = useMemo(() => {
        if (!many || typeof window === 'undefined') {
            return 0;
        }

        const saved = window.localStorage.getItem(`cm:script-tab:${storageKey}`);
        const parsed = saved !== null ? Number.parseInt(saved, 10) : 0;

        return Number.isNaN(parsed) || parsed >= scripts.length ? 0 : parsed;
    }, [many, scripts.length, storageKey]);

    const [activeIndex, setActiveIndex] = useState(initialIndex);

    if (scripts.length === 0) {
        return <p className="empty">No script yet.</p>;
    }

    const active = scripts[activeIndex] ?? scripts[0];

    return (
        <section className="pane script-pane">
            <div className="pane-head">
                <span className="k">
                    <b>#{videoNumber}</b> · Script
                </span>
            </div>

            {many && (
                <div className="langsw" role="tablist">
                    {scripts.map((script, index) => (
                        <button
                            key={script.lang}
                            type="button"
                            role="tab"
                            aria-selected={index === activeIndex}
                            onClick={() => {
                                setActiveIndex(index);
                                window.localStorage.setItem(
                                    `cm:script-tab:${storageKey}`,
                                    String(index),
                                );
                            }}
                        >
                            {script.lang}
                        </button>
                    ))}
                </div>
            )}

            <div
                className="script"
                dangerouslySetInnerHTML={{
                    __html: renderScriptBodyHtml(active.body),
                }}
            />
        </section>
    );
}
