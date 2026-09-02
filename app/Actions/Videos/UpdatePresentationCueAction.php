<?php

namespace App\Actions\Videos;

use App\Data\Videos\UpdatePresentationCueData;
use App\Models\Video;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class UpdatePresentationCueAction
{
    public function handle(Video $video, UpdatePresentationCueData $data): void
    {
        $currentCue = trim($data->currentCue);
        $cue = trim($data->cue);

        if ($cue === '' || preg_match('/[\r\n]/', $cue) === 1) {
            throw new InvalidArgumentException('Cue must be a non-empty single line.');
        }

        DB::transaction(function () use ($video, $data, $currentCue, $cue): void {
            $video = Video::query()
                ->whereKey($video->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $markdown = $video->script_markdown;
            $manifest = $video->deck_manifest;
            $deckJs = is_array($manifest) ? ($manifest['js'] ?? null) : null;

            if (! is_string($markdown) || $markdown === '') {
                throw new InvalidArgumentException('This presentation has no script to edit.');
            }

            if (! is_array($manifest) || ! is_string($deckJs) || $deckJs === '') {
                throw new InvalidArgumentException('This video has no editable presentation deck.');
            }

            $deckCues = $this->deckCueLiterals($deckJs);
            $deckCue = $deckCues[$data->step] ?? null;
            if ($deckCue === null || ($deckCue['editable'] ?? true) === false) {
                throw new InvalidArgumentException('This presentation step is not linked to an editable script line.');
            }

            $scriptLine = $this->lineForStep(
                $this->spokenLines($markdown),
                $data->step,
                $currentCue,
                count($deckCues),
                $deckCue['scriptLine'] ?? null,
            );
            $updatedMarkdown = substr_replace(
                $markdown,
                $this->replaceSpokenText($scriptLine['text'], $cue),
                $scriptLine['offset'],
                $scriptLine['length'],
            );
            $manifest['js'] = $this->replaceDeckCue($deckJs, $currentCue, $cue, $data->step);

            $video->forceFill([
                'script_markdown' => $updatedMarkdown,
                'deck_manifest' => $manifest,
            ])->save();
        });
    }

    /**
     * @param  list<array{text: string, cue: string, offset: int, length: int}>  $lines
     * @return array{text: string, cue: string, offset: int, length: int}
     */
    private function lineForStep(
        array $lines,
        int $step,
        string $currentCue,
        int $deckCueCount,
        ?int $scriptLineIndex = null,
    ): array {
        if ($scriptLineIndex !== null) {
            $mappedLine = $lines[$scriptLineIndex] ?? null;
            if ($mappedLine !== null) {
                return $mappedLine;
            }

            throw new InvalidArgumentException('Presentation step no longer matches its script line. Reload and try again.');
        }

        $line = $lines[$step] ?? null;
        if ($line !== null && $line['cue'] === $currentCue) {
            return $line;
        }

        $matches = array_values(array_filter(
            $lines,
            fn (array $candidate): bool => $candidate['cue'] === $currentCue,
        ));

        if (count($matches) === 1) {
            return $matches[0];
        }

        // Older decks use shortened cues, but positional fallback is safe only
        // when both artifacts still expose the same number of spoken steps.
        if ($line !== null && count($lines) === $deckCueCount) {
            return $line;
        }

        throw new InvalidArgumentException('Presentation step no longer matches its script line. Reload and try again.');
    }

    /**
     * @return list<array{text: string, cue: string, offset: int, length: int}>
     */
    private function spokenLines(string $markdown): array
    {
        preg_match_all('/```[^\r\n`]*\r?\n(.*?)```/s', $markdown, $blocks, PREG_OFFSET_CAPTURE);
        $lines = [];

        foreach ($blocks[1] as $block) {
            $body = (string) $block[0];
            $bodyOffset = (int) $block[1];
            preg_match_all('/[^\r\n]+/', $body, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as $match) {
                $text = (string) $match[0];
                $trimmed = trim($text);
                if ($trimmed === '' || $this->isDirectionLine($trimmed)) {
                    continue;
                }

                $lines[] = [
                    'text' => $text,
                    'cue' => $this->spokenCue($text),
                    'offset' => $bodyOffset + (int) $match[1],
                    'length' => strlen($text),
                ];
            }
        }

        return $lines;
    }

    private function isDirectionLine(string $line): bool
    {
        return preg_match('/^\[[^\]\r\n]+\]$/u', $line) === 1;
    }

    private function spokenCue(string $line): string
    {
        $spoken = preg_replace('/\s*\[[^\]\r\n]*\]/u', '', $line);

        return trim($spoken ?? $line);
    }

    private function replaceSpokenText(string $line, string $cue): string
    {
        $leadingLength = strlen($line) - strlen(ltrim($line));
        $trailingStart = strlen(rtrim($line));
        $content = substr($line, $leadingLength, $trailingStart - $leadingLength);

        preg_match_all('/\[[^\]\r\n]*\]/u', $content, $directions, PREG_OFFSET_CAPTURE);
        $directionMatches = $directions[0];

        foreach ($directionMatches as $direction) {
            $directionStart = (int) $direction[1];
            $directionEnd = $directionStart + strlen((string) $direction[0]);

            if (trim(substr($content, 0, $directionStart)) !== '' && trim(substr($content, $directionEnd)) !== '') {
                throw new InvalidArgumentException('Cannot edit a script line with an inline direction. Move the direction to the end of the line and try again.');
            }
        }

        $directionText = implode(' ', array_map(
            fn (array $direction): string => (string) $direction[0],
            $directionMatches,
        ));

        return substr($line, 0, $leadingLength)
            .$cue.($directionText !== '' ? ' '.$directionText : '')
            .substr($line, $trailingStart);
    }

    private function replaceDeckCue(string $js, string $currentCue, string $cue, int $step): string
    {
        $target = $this->deckCueLiterals($js)[$step] ?? null;

        if ($target === null || ! $this->matchesCue($target['literal'], $currentCue)) {
            throw new InvalidArgumentException('Presentation step no longer matches its deck cue. Reload and try again.');
        }

        $replacement = $target['literal'][0] === "'"
            ? "'".$this->escapeSingleQuoted($cue)."'"
            : $this->encodeDoubleQuoted($cue, true);

        return substr_replace($js, $replacement, $target['offset'], strlen($target['literal']));
    }

    /**
     * @return list<array{literal: string, offset: int, scriptLine?: int, editable?: bool}|null>
     */
    private function deckCueLiterals(string $js): array
    {
        $source = $this->withoutJavaScriptComments($js);

        if (preg_match('/\bsteps\s*:\s*([A-Za-z_$][A-Za-z0-9_$]*)\s*\.\s*map\b/', $source, $stepsMatch, PREG_OFFSET_CAPTURE) === 1) {
            $linesName = (string) $stepsMatch[1][0];
            $declarationPattern = '/\b(?:const|let|var)\s+'.preg_quote($linesName, '/').'\s*=\s*\[/s';
            if (preg_match($declarationPattern, $source, $declarationMatch, PREG_OFFSET_CAPTURE) !== 1) {
                return [];
            }

            $declaration = (string) $declarationMatch[0][0];
            $openOffset = (int) $declarationMatch[0][1] + strrpos($declaration, '[');
            $array = $this->arrayBody($source, $openOffset);

            return $array === null ? [] : $this->stringLiterals($array['body'], $array['offset']);
        }

        if (preg_match('/\bsteps\s*:\s*\[/s', $source, $stepsMatch, PREG_OFFSET_CAPTURE) !== 1) {
            return [];
        }

        $stepsExpression = (string) $stepsMatch[0][0];
        $openOffset = (int) $stepsMatch[0][1] + strrpos($stepsExpression, '[');
        $array = $this->arrayBody($source, $openOffset);
        if ($array === null) {
            return [];
        }

        $steps = [];
        foreach ($this->arrayElements($array['body'], $array['offset']) as $element) {
            preg_match(
                '/\bcue\s*:\s*(\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*")/s',
                $element['body'],
                $match,
                PREG_OFFSET_CAPTURE,
            );

            if (isset($match[1])) {
                $step = [
                    'literal' => (string) $match[1][0],
                    'offset' => $element['offset'] + (int) $match[1][1],
                ];

                if (preg_match('/\bscriptLine\s*:\s*(\d+)/', $element['body'], $scriptLineMatch) === 1) {
                    $step['scriptLine'] = (int) $scriptLineMatch[1];
                }
                if (preg_match('/\beditable\s*:\s*(true|false)\b/', $element['body'], $editableMatch) === 1) {
                    $step['editable'] = $editableMatch[1] === 'true';
                }

                $steps[] = $step;

                continue;
            }

            $trimmed = ltrim($element['body']);
            if (isset($trimmed[0]) && ($trimmed[0] === "'" || $trimmed[0] === '"')) {
                $literals = $this->stringLiterals($element['body'], $element['offset']);
                if (count($literals) === 1) {
                    $step = $literals[0];
                    if (preg_match('/\bscriptLine\s*:\s*(\d+)/', $element['body'], $scriptLineMatch) === 1) {
                        $step['scriptLine'] = (int) $scriptLineMatch[1];
                    }
                    if (preg_match('/\beditable\s*:\s*(true|false)\b/', $element['body'], $editableMatch) === 1) {
                        $step['editable'] = $editableMatch[1] === 'true';
                    }

                    $steps[] = $step;

                    continue;
                }
            }

            // A note-only step still occupies a deck index, but is not editable.
            $steps[] = null;
        }

        return $steps;
    }

    /**
     * @return list<array{body: string, offset: int}>
     */
    private function arrayElements(string $body, int $offsetBase): array
    {
        $elements = [];
        $start = 0;
        $squareDepth = 0;
        $curlyDepth = 0;
        $parenDepth = 0;
        $quote = null;
        $length = strlen($body);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $body[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === '[') {
                $squareDepth++;
            } elseif ($character === ']') {
                $squareDepth--;
            } elseif ($character === '{') {
                $curlyDepth++;
            } elseif ($character === '}') {
                $curlyDepth--;
            } elseif ($character === '(') {
                $parenDepth++;
            } elseif ($character === ')') {
                $parenDepth--;
            } elseif ($character === ',' && $squareDepth === 0 && $curlyDepth === 0 && $parenDepth === 0) {
                $element = substr($body, $start, $offset - $start);
                if (trim($element) !== '') {
                    $elements[] = [
                        'body' => $element,
                        'offset' => $offsetBase + $start,
                    ];
                }
                $start = $offset + 1;
            }
        }

        $element = substr($body, $start);
        if (trim($element) !== '') {
            $elements[] = [
                'body' => $element,
                'offset' => $offsetBase + $start,
            ];
        }

        return $elements;
    }

    /**
     * @return array{body: string, offset: int}|null
     */
    private function arrayBody(string $source, int $openOffset): ?array
    {
        if (($source[$openOffset] ?? null) !== '[') {
            return null;
        }

        $depth = 0;
        $quote = null;
        $length = strlen($source);

        for ($offset = $openOffset; $offset < $length; $offset++) {
            $character = $source[$offset];

            if ($quote !== null) {
                if ($character === '\\') {
                    $offset++;
                } elseif ($character === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
            } elseif ($character === '[') {
                $depth++;
            } elseif ($character === ']') {
                $depth--;
                if ($depth === 0) {
                    return [
                        'body' => substr($source, $openOffset + 1, $offset - $openOffset - 1),
                        'offset' => $openOffset + 1,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return list<array{literal: string, offset: int}>
     */
    private function stringLiterals(string $source, int $offsetBase): array
    {
        preg_match_all(
            '/\'(?:\\\\.|[^\'\\\\])*\'|"(?:\\\\.|[^"\\\\])*"/s',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE,
        );

        $literals = [];
        foreach ($matches[0] as $match) {
            $literals[] = [
                'literal' => (string) $match[0],
                'offset' => $offsetBase + (int) $match[1],
            ];
        }

        return $literals;
    }

    private function withoutJavaScriptComments(string $js): string
    {
        $source = $js;
        $length = strlen($source);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $source[$offset];

            if ($character === "'" || $character === '"' || $character === '`') {
                $quote = $character;
                for ($offset++; $offset < $length; $offset++) {
                    if ($source[$offset] === '\\') {
                        $offset++;
                    } elseif ($source[$offset] === $quote) {
                        break;
                    }
                }

                continue;
            }

            if ($character === '/' && ($source[$offset + 1] ?? '') === '/') {
                for ($offset += 2; $offset < $length && ! in_array($source[$offset], ["\r", "\n"], true); $offset++) {
                    $source[$offset] = ' ';
                }
                $offset--;
            } elseif ($character === '/' && ($source[$offset + 1] ?? '') === '*') {
                for ($offset += 2; $offset < $length - 1; $offset++) {
                    if ($source[$offset] === '*' && $source[$offset + 1] === '/') {
                        $source[$offset] = ' ';
                        $source[$offset + 1] = ' ';
                        $offset++;
                        break;
                    }
                    if (! in_array($source[$offset], ["\r", "\n"], true)) {
                        $source[$offset] = ' ';
                    }
                }
            }
        }

        return $source;
    }

    private function matchesCue(string $literal, string $cue): bool
    {
        return $this->decodeJavaScriptLiteral($literal) === $cue;
    }

    private function decodeJavaScriptLiteral(string $literal): ?string
    {
        $quote = $literal[0] ?? '';
        if (! in_array($quote, ["'", '"'], true) || ! str_ends_with($literal, $quote)) {
            return null;
        }

        $body = substr($literal, 1, -1);
        $decoded = preg_replace_callback('/\\\\(u\{[0-9a-fA-F]{1,6}\}|u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|[0-7]{1,3}|.)/s', function (array $match): string {
            $escape = $match[1];

            return match (true) {
                str_starts_with($escape, 'u{') => $this->unicodeCodePoint((int) hexdec(substr($escape, 2, -1))),
                $escape[0] === 'u' => $this->unicodeCodePoint((int) hexdec(substr($escape, 1))),
                $escape[0] === 'x' => pack('C', (int) hexdec(substr($escape, 1))),
                $escape[0] === 'n' => "\n",
                $escape[0] === 'r' => "\r",
                $escape[0] === 't' => "\t",
                $escape[0] === 'b' => "\x08",
                $escape[0] === 'f' => "\x0c",
                $escape[0] === 'v' => "\x0b",
                $escape[0] === '0' => "\0",
                default => $escape,
            };
        }, $body);

        return $decoded ?? $body;
    }

    private function unicodeCodePoint(int $codePoint): string
    {
        if ($codePoint <= 0x7F) {
            return pack('C', $codePoint);
        }
        if ($codePoint <= 0x7FF) {
            return pack('C*', 0xC0 | ($codePoint >> 6), 0x80 | ($codePoint & 0x3F));
        }
        if ($codePoint <= 0xFFFF) {
            return pack('C*', 0xE0 | ($codePoint >> 12), 0x80 | (($codePoint >> 6) & 0x3F), 0x80 | ($codePoint & 0x3F));
        }

        return pack('C*', 0xF0 | ($codePoint >> 18), 0x80 | (($codePoint >> 12) & 0x3F), 0x80 | (($codePoint >> 6) & 0x3F), 0x80 | ($codePoint & 0x3F));
    }

    private function encodeDoubleQuoted(string $value, bool $safe): string
    {
        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR;
        if ($safe) {
            $flags |= JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        }

        return (string) json_encode($value, $flags);
    }

    private function escapeSingleQuoted(string $value): string
    {
        return str_replace(
            ['\\', "'", '<', "\r", "\n", "\u{2028}", "\u{2029}"],
            ['\\\\', "\\'", '\\x3C', '\\r', '\\n', '\\u2028', '\\u2029'],
            $value,
        );
    }
}
