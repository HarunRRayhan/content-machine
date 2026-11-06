<?php

namespace App\Actions\Ids;

use App\Models\ContentId;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Atomically reserves the next human-readable id for a workspace+kind (e.g.
 * "PI-7"), backed by a per-workspace+kind counter in `id_sequences`.
 *
 * Always reserved as `web` in this phase: the only caller today is a future
 * web-dashboard promotion flow. A CLI/API/Telegram caller in a later phase
 * would need this signature extended to accept `reserved_via` explicitly.
 */
class ReserveContentIdAction
{
    public function handle(Workspace $workspace, string $kind, ?string $idempotencyKey = null): ContentId
    {
        return DB::transaction(function () use ($workspace, $kind, $idempotencyKey) {
            if ($idempotencyKey !== null) {
                $existing = ContentId::where('idempotency_key', $idempotencyKey)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            $number = $this->nextNumber($workspace, $kind);

            return ContentId::create([
                'workspace_id' => $workspace->id,
                'kind' => $kind,
                'number' => $number,
                'human_id' => $this->humanId($kind, $number),
                'reserved_by_user_id' => Auth::id(),
                'reserved_via' => 'web',
                'idempotency_key' => $idempotencyKey,
                'reserved_at' => now(),
            ]);
        });
    }

    /** Highest number an import may carry; keeps every id far below int overflow. */
    public const MAX_IMPORT_NUMBER = 999999;

    /** How far above the posts.id sequence an imported P- number may sit before it is refused. */
    public const MAX_IMPORT_GAP = 1000;

    private const POST_ID_LOCK = 7253001;

    /**
     * New posts: the number IS the posts.id (one id; "P-" is only a display
     * label). Takes the next value of the posts.id sequence for the caller to
     * insert with, and keeps the legacy counter above it. Call inside the
     * creating transaction.
     */
    public function nextPostId(Workspace $workspace): int
    {
        $this->lockPostIds();

        // An imported P-{id} (or a reserved one) may already own this label: skip it.
        do {
            $id = (int) DB::selectOne("select nextval(pg_get_serial_sequence('posts', 'id')) as id")->id;
        } while (DB::table('posts')->where('workspace_id', $workspace->id)->where('human_id', "P-{$id}")->exists());

        $this->advancePast($workspace, 'post', $id);

        return $id;
    }

    /**
     * Keep a generated id above an explicitly imported number (legacy counter only).
     */
    public function advancePast(Workspace $workspace, string $kind, int $number): void
    {
        if ($number < 1) {
            throw new InvalidArgumentException('A content id number must be positive.');
        }

        DB::table('id_sequences')->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'next_value' => 1,
        ]);

        $sequence = DB::table('id_sequences')
            ->where('workspace_id', $workspace->id)
            ->where('kind', $kind)
            ->lockForUpdate()
            ->first();

        if ($sequence !== null && (int) $sequence->next_value <= $number) {
            DB::table('id_sequences')
                ->where('workspace_id', $workspace->id)
                ->where('kind', $kind)
                ->update(['next_value' => $number + 1]);
        }
    }

    /**
     * An imported P-{number} label must stay ahead of the global posts.id sequence so a
     * generated id never reuses it. Never jumps the shared sequence far: a number at or
     * below the sequence needs nothing, one slightly above moves it, one far above is refused.
     */
    public function advancePostSequence(int $number): void
    {
        $this->lockPostIds();

        $sequence = DB::selectOne("select pg_get_serial_sequence('posts', 'id') as name")->name;
        $last = (int) DB::selectOne("select last_value from {$sequence}")->last_value;

        if ($number <= $last) {
            return;
        }

        if ($number > $last + self::MAX_IMPORT_GAP) {
            throw ValidationException::withMessages([
                'number' => 'number is too far above the current post ids to import.',
            ]);
        }

        DB::selectOne('select setval(?, ?)', [$sequence, $number]);
    }

    /** Serializes nextval/setval on posts.id so an import can never move the sequence backwards. */
    private function lockPostIds(): void
    {
        DB::select('select pg_advisory_xact_lock(?)', [self::POST_ID_LOCK]);
    }

    /**
     * Atomically increment the workspace+kind counter and return the number
     * it held before incrementing. `insertOrIgnore` seeds a missing row
     * without racing another caller doing the same (the unique index on
     * `id_sequences` makes the loser a no-op); `lockForUpdate` then takes a
     * row-level lock so nothing else can read/increment this row until this
     * transaction commits, making the whole read-then-write atomic.
     */
    private function nextNumber(Workspace $workspace, string $kind): int
    {
        DB::table('id_sequences')->insertOrIgnore([
            'workspace_id' => $workspace->id,
            'kind' => $kind,
            'next_value' => 1,
        ]);

        $sequence = DB::table('id_sequences')
            ->where('workspace_id', $workspace->id)
            ->where('kind', $kind)
            ->lockForUpdate()
            ->first();

        $number = $sequence->next_value;

        DB::table('id_sequences')
            ->where('workspace_id', $workspace->id)
            ->where('kind', $kind)
            ->update(['next_value' => $number + 1]);

        return $number;
    }

    private function humanId(string $kind, int $number): string
    {
        $prefix = config("ids.prefixes.{$kind}");

        if (! is_string($prefix)) {
            throw new InvalidArgumentException("No id prefix configured for kind [{$kind}] in config/ids.php.");
        }

        return "{$prefix}-{$number}";
    }
}
