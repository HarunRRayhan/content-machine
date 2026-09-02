<?php

namespace App\Support\Content;

final class PresentationManifest
{
    /**
     * A manifest is usable only when the imported deck JavaScript can be
     * rendered by the presentation route and editor.
     *
     * @param  array<string, mixed>|null  $manifest
     */
    public static function isUsable(?array $manifest): bool
    {
        $js = $manifest['js'] ?? null;
        $deckKey = $manifest['deck_key'] ?? null;

        if (! is_string($js) || trim($js) === '' || ! is_string($deckKey) || trim($deckKey) === '') {
            return false;
        }

        $source = self::withoutJavaScriptComments($js);

        if (! self::registersDeck($source, $deckKey)) {
            return false;
        }

        return preg_match('/\b(?:stage|slidesHtml)\s*:\s*function\b/', $source) === 1
            && preg_match('/\bsteps\s*:/', $source) === 1
            && preg_match('/\b(?:cue|note)\s*:/', $source) === 1;
    }

    private static function registersDeck(string $source, string $deckKey): bool
    {
        $keys = [$deckKey];
        if (preg_match('/^(?:bv|ev)-(\d+)$/i', $deckKey, $match) === 1) {
            $keys[] = 'v-'.$match[1];
        }

        foreach (array_unique($keys) as $key) {
            $pattern = "/(?:window\\.)?\\bPRESENTATIONS\\s*\\[\\s*(['\"])".
                preg_quote($key, '/').
                '\\1\\s*\\]/';

            if (preg_match($pattern, $source) === 1) {
                return true;
            }
        }

        return false;
    }

    private static function withoutJavaScriptComments(string $js): string
    {
        $source = $js;
        $length = strlen($source);

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $source[$offset];

            if (in_array($character, ["'", '"', '`'], true)) {
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
}
