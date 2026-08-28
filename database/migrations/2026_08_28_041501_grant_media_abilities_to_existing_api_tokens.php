<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Grant media:read and media:write to every non-revoked workspace API token.
 */
return new class extends Migration
{
    private const NEW_ABILITIES = ['media:read', 'media:write'];

    public function up(): void
    {
        $this->rewriteAbilities(fn (array $abilities): array => array_values(array_unique([
            ...$abilities,
            ...self::NEW_ABILITIES,
        ])));
    }

    public function down(): void
    {
        $this->rewriteAbilities(fn (array $abilities): array => array_values(array_filter(
            $abilities,
            fn (mixed $ability): bool => ! in_array($ability, self::NEW_ABILITIES, true),
        )));
    }

    /**
     * @param  callable(array<int, string>): array<int, string>  $transform
     */
    private function rewriteAbilities(callable $transform): void
    {
        DB::table('workspace_api_tokens')
            ->whereNull('revoked_at')
            ->orderBy('id')
            ->each(function (object $token) use ($transform): void {
                $abilities = is_array($token->abilities)
                    ? $token->abilities
                    : json_decode((string) $token->abilities, true);

                if (! is_array($abilities)) {
                    $abilities = [];
                }

                /** @var array<int, string> $abilities */
                $abilities = array_values(array_filter(
                    $abilities,
                    fn (mixed $ability): bool => is_string($ability),
                ));

                $next = $transform($abilities);

                if ($next === $abilities) {
                    return;
                }

                DB::table('workspace_api_tokens')
                    ->where('id', $token->id)
                    ->update([
                        'abilities' => json_encode($next),
                        'updated_at' => now(),
                    ]);
            });
    }
};
