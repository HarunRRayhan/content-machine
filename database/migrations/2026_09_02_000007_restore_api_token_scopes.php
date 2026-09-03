<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The two historical ability migrations ran on some installations and
 * expanded tokens that predated those API surfaces. Remove only those
 * implicit grants from tokens that existed before each feature shipped;
 * abilities selected on a newly minted token remain untouched.
 */
return new class extends Migration
{
    private const POST_VIDEO_MIGRATION_AT = '2026-08-25 06:53:36+00';

    private const MEDIA_MIGRATION_AT = '2026-08-28 04:15:01+00';

    private const DRIVE_MIGRATION_AT = '2026-09-01 00:00:00+00';

    private const POST_VIDEO_ABILITIES = [
        'videos:read',
        'videos:write',
        'posts:read',
        'posts:write',
    ];

    private const MEDIA_ABILITIES = ['media:read', 'media:write'];

    public function up(): void
    {
        $this->removeImplicitAbilities(
            self::POST_VIDEO_MIGRATION_AT,
            self::POST_VIDEO_ABILITIES,
        );
        $this->removeImplicitAbilities(
            self::MEDIA_MIGRATION_AT,
            self::MEDIA_ABILITIES,
        );
        $this->removeImplicitAbilities(
            self::DRIVE_MIGRATION_AT,
            ['drive:read', 'drive:write'],
        );
    }

    public function down(): void {}

    /**
     * @param  list<string>  $removedAbilities
     */
    private function removeImplicitAbilities(string $createdBefore, array $removedAbilities): void
    {
        DB::table('workspace_api_tokens')
            ->where('created_at', '<', $createdBefore)
            ->orderBy('id')
            ->each(function (object $token) use ($removedAbilities): void {
                $abilities = json_decode((string) $token->abilities, true);
                if (! is_array($abilities)) {
                    return;
                }

                $next = array_values(array_filter(
                    $abilities,
                    fn (mixed $ability): bool => is_string($ability)
                        && ! in_array($ability, $removedAbilities, true),
                ));

                if ($next === $abilities) {
                    return;
                }

                DB::table('workspace_api_tokens')
                    ->where('id', $token->id)
                    ->update([
                        'abilities' => json_encode($next, JSON_THROW_ON_ERROR),
                        'updated_at' => now(),
                    ]);
            });
    }
};
