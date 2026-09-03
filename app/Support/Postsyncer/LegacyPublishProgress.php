<?php

namespace App\Support\Postsyncer;

final class LegacyPublishProgress
{
    private const MISSING_ACCOUNT_MARKER = 'No account id mapped for platform ';

    /**
     * Older publishers could upload media before discovering a missing account
     * mapping. Those records are safe to repair because no create was sent.
     *
     * @param  array<string, mixed>|null  $progress
     */
    public static function isMissingAccountFailure(?string $error, ?array $progress): bool
    {
        $hasRepairMarker = is_array($progress)
            && ($progress['legacy_repair'] ?? null) === 'missing_account';

        if (! $hasRepairMarker
            && (! is_string($error) || ! str_contains($error, self::MISSING_ACCOUNT_MARKER))) {
            return false;
        }

        if (! is_array($progress)
            || ! in_array(($progress['state'] ?? null), ['uncertain', 'failed'], true)) {
            return false;
        }

        $current = $progress['current'] ?? null;

        return is_array($current)
            && in_array(($current['phase'] ?? null), ['creating', 'retryable'], true)
            && is_int($current['index'] ?? null)
            && is_array($current['media_ids'] ?? null)
            && ! array_key_exists('expected_payload', $current);
    }

    /**
     * @param  array<string, mixed>  $progress
     * @return array<string, mixed>
     */
    public static function markRetryable(array $progress): array
    {
        $progress['legacy_repair'] = 'missing_account';
        $progress['state'] = 'failed';
        $progress['current']['phase'] = 'retryable';

        return $progress;
    }
}
