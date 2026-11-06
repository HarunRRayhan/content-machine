<?php

namespace App\Support\Socialreins;

/**
 * Brain signs every webhook body: header `SocialReins-Signature: t=<unix>,v1=<hex>` where
 * v1 is HMAC-SHA256 of "<t>.<raw body>" keyed by the whole `whsec_...` secret.
 */
final class WebhookSignature
{
    /** How far the signed time may be from now, either way. */
    public const TOLERANCE_SECONDS = 300;

    public static function verify(string $secret, ?string $header, string $body, ?int $now = null): bool
    {
        if ($header === null || $secret === '') {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$name, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$name] = $value;
        }

        $time = $parts['t'] ?? '';
        $given = $parts['v1'] ?? '';

        if (! ctype_digit($time) || $given === '') {
            return false;
        }

        if (abs(($now ?? time()) - (int) $time) > self::TOLERANCE_SECONDS) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', "{$time}.{$body}", $secret), $given);
    }

    public static function sign(string $secret, int $time, string $body): string
    {
        return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $secret);
    }
}
