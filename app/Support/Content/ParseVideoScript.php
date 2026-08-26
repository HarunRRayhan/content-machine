<?php

namespace App\Support\Content;

/**
 * Re-parse stored script markdown into the shape Script Studio's UI expects.
 * Ported from personal-content/web/build.py (parse_script, parse_scripts, …).
 */
final class ParseVideoScript
{
    /** @var array<string, string> */
    private const LANGMAP = [
        'bn' => 'Bangla',
        'bengali' => 'Bangla',
        'bangla' => 'Bangla',
        'en' => 'English',
        'english' => 'English',
    ];

    /**
     * @return array{
     *     lang: string,
     *     length: string,
     *     parts: int,
     *     points: list<array{label: string, text: string}>,
     *     scripts: list<array{lang: string, body: string}>,
     *     facts: list<string>,
     *     sources: string,
     *     legal: list<string>,
     * }
     */
    public static function fromMarkdown(string $markdown, string $fallbackLang = 'bn'): array
    {
        $text = $markdown !== '' ? $markdown : "# Untitled\n";
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];

        $metaLine = '';
        foreach ($lines as $line) {
            if (str_contains($line, '**Language:**')) {
                $metaLine = $line;
                break;
            }
        }

        $meta = self::parseMeta($metaLine);
        $lang = self::normLang($meta['language'] ?? ($fallbackLang === 'en' ? 'English' : 'Bangla'));

        $points = [];
        foreach (self::region($lines, '/Talking points/', ['/^---\s*$/']) as $line) {
            if (preg_match('/^(?:\d+\.|[-*])\s+(.*)$/', trim($line), $match)) {
                if (preg_match('/^\*\*(.+?)\*\*\s*[-:]\s*(.*)$/', $match[1], $labeled)) {
                    $points[] = ['label' => trim($labeled[1]), 'text' => trim($labeled[2])];
                } else {
                    $points[] = ['label' => '', 'text' => trim($match[1])];
                }
            }
        }

        $scripts = self::parseScripts($text, $meta['language'] ?? $lang);

        $parts = 1;
        if (preg_match('/(\d+)/', $meta['series'] ?? '', $seriesMatch)) {
            $parts = max(1, (int) $seriesMatch[1]);
        } else {
            $partCount = 0;
            foreach ($scripts as $script) {
                if (str_starts_with($script['lang'], 'Part ')) {
                    $partCount++;
                }
            }
            if ($partCount > 0) {
                $parts = $partCount;
            }
        }

        $fcLines = self::region($lines, '/^## .*Fact-check/', ['/^## /']);
        $sources = '';
        $facts = [];

        $srcIdx = null;
        foreach ($fcLines as $index => $line) {
            if (str_starts_with(trim($line), 'Sources:')) {
                $srcIdx = $index;
                break;
            }
        }

        if ($srcIdx !== null) {
            $facts = self::mergeBullets(array_slice($fcLines, 0, $srcIdx));
            $sources = trim(str_replace('Sources:', '', implode(' ', array_map('trim', array_slice($fcLines, $srcIdx)))));
        } else {
            $facts = self::mergeBullets($fcLines);
        }

        $legal = self::mergeBullets(self::region($lines, '/Legal Note/', ['/^## /']));

        return [
            'lang' => $lang,
            'length' => $meta['est. length'] ?? '',
            'parts' => $parts,
            'points' => $points,
            'scripts' => $scripts,
            'facts' => $facts,
            'sources' => $sources,
            'legal' => $legal,
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function parseMeta(string $line): array
    {
        $meta = [];
        if (preg_match_all('/\*\*(.+?):\*\*\s*([^·]+)/u', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $meta[strtolower(trim($match[1]))] = trim($match[2]);
            }
        }

        return $meta;
    }

    private static function normLang(string $value): string
    {
        $key = strtolower(trim($value));
        if ($key === '') {
            return '';
        }

        return self::LANGMAP[$key] ?? trim($value);
    }

    /**
     * @param  list<string>  $lines
     * @param  list<string>  $stops
     * @return list<string>
     */
    private static function region(array $lines, string $headerPattern, array $stops): array
    {
        $out = [];
        $capturing = false;

        foreach ($lines as $line) {
            if ($capturing) {
                foreach ($stops as $stop) {
                    if (preg_match($stop, $line)) {
                        return $out;
                    }
                }
                $out[] = $line;
            } elseif (preg_match($headerPattern, $line)) {
                $capturing = true;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private static function mergeBullets(array $lines): array
    {
        $bullets = [];
        $current = null;

        foreach ($lines as $line) {
            if (str_starts_with(trim($line), '- ')) {
                if ($current !== null) {
                    $bullets[] = trim($current);
                }
                $current = substr(trim($line), 2);
            } elseif ($current !== null && trim($line) !== '' && ! str_starts_with(trim($line), '#')) {
                $current .= ' '.trim($line);
            }
        }

        if ($current !== null) {
            $bullets[] = trim($current);
        }

        return array_values(array_filter($bullets, fn (string $item) => $item !== ''));
    }

    /**
     * @return list<array{lang: string, body: string}>
     */
    private static function parseScripts(string $text, string $metaLang): array
    {
        preg_match_all('/```([^\n`]*)\n(.*?)```/s', $text, $blocks, PREG_SET_ORDER);

        $fileLabel = in_array(strtolower(trim($metaLang)), ['bangla', 'bengali', 'bn'], true)
            ? 'Bangla'
            : (trim($metaLang) !== '' ? trim($metaLang) : 'Script');

        $parsed = [];
        foreach ($blocks as $block) {
            $info = trim($block[1]);
            $body = rtrim($block[2], "\n");
            $lang = null;
            $explicit = null;

            if ($info !== '') {
                $parts = explode(' ', $info, 2);
                $first = strtolower($parts[0]);
                if (isset(self::LANGMAP[$first])) {
                    $lang = self::LANGMAP[$first];
                    $explicit = isset($parts[1]) ? trim($parts[1]) : null;
                    if ($explicit === '') {
                        $explicit = null;
                    }
                } else {
                    $explicit = $info;
                }
            }

            $parsed[] = ['lang' => $lang, 'explicit' => $explicit, 'body' => $body];
        }

        $count = count($parsed);
        $langs = array_values(array_filter(array_column($parsed, 'lang')));
        $isLangSwitch = count(array_unique($langs)) > 1;

        $scripts = [];
        foreach ($parsed as $index => $item) {
            if ($item['explicit']) {
                $tab = $item['explicit'];
            } elseif ($isLangSwitch && $item['lang']) {
                $tab = $item['lang'];
            } elseif ($count > 1) {
                $tab = 'Part '.($index + 1);
            } else {
                $tab = $item['lang'] ?: $fileLabel;
            }

            $scripts[] = ['lang' => $tab, 'body' => $item['body']];
        }

        return $scripts;
    }
}
