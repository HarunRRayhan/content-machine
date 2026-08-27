<?php

namespace App\Support\Postsyncer;

/**
 * Flatten PostSyncer account rows onto the Settings platform grid.
 */
final class MapPostsyncerAccounts
{
    /**
     * @param  list<string>  $platforms
     * @param  array<mixed>  $accounts
     * @param  array<string, mixed>  $existing
     * @return array<string, array{account_id: int|string|null, handle: string, enabled: bool}>
     */
    public static function toPlatforms(array $platforms, array $accounts, array $existing = []): array
    {
        $suggested = [];

        foreach ($platforms as $platform) {
            $current = is_array($existing[$platform] ?? null) ? $existing[$platform] : [];
            $suggested[$platform] = [
                'account_id' => $current['account_id'] ?? null,
                'handle' => self::handle($current['handle'] ?? ''),
                'enabled' => self::enabled($current, filled($current['account_id'] ?? null)),
            ];
        }

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $platform = self::platformName($account['platform'] ?? '');

            if ($platform === '' || ! array_key_exists($platform, $suggested)) {
                continue;
            }

            $current = is_array($existing[$platform] ?? null) ? $existing[$platform] : [];
            $username = $account['username'] ?? $account['handle'] ?? $account['name'] ?? $account['display_name'] ?? null;
            $handle = is_string($username) && $username !== ''
                ? self::handle($username)
                : $suggested[$platform]['handle'];

            $suggested[$platform] = [
                'account_id' => $account['id'] ?? $suggested[$platform]['account_id'],
                'handle' => $handle,
                'enabled' => array_key_exists('enabled', $current)
                    ? self::enabled($current, true)
                    : true,
            ];
        }

        return $suggested;
    }

    /**
     * @param  array<mixed>  $accounts
     * @return list<array{id: string, platform: string, handle: string}>
     */
    public static function present(array $accounts): array
    {
        $presented = [];

        foreach ($accounts as $account) {
            if (! is_array($account)) {
                continue;
            }

            $id = $account['id'] ?? null;
            $platform = self::platformName($account['platform'] ?? '');

            if ($id === null || $id === '' || $platform === '') {
                continue;
            }

            $username = $account['username'] ?? $account['handle'] ?? $account['name'] ?? $account['display_name'] ?? '';

            $presented[] = [
                'id' => (string) $id,
                'platform' => $platform,
                'handle' => is_string($username) ? self::handle($username) : '',
            ];
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public static function enabled(array $entry, bool $fallback): bool
    {
        if (! array_key_exists('enabled', $entry)) {
            return $fallback;
        }

        $value = $entry['enabled'];

        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $fallback;
    }

    public static function platformName(mixed $platform): string
    {
        $name = strtolower(trim((string) $platform));

        return $name === 'x' ? 'twitter' : $name;
    }

    public static function handle(mixed $value): string
    {
        if (! is_string($value) || $value === '') {
            return '';
        }

        return str_starts_with($value, '@') ? $value : '@'.$value;
    }
}
