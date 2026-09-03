<?php

namespace App\Actions\Ids;

use App\Models\ContentId;
use App\Models\Workspace;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

    /**
     * Keep a reservation sequence ahead of an explicitly imported number.
     * Imported ids are not reservations themselves, but a later generated id
     * must not reuse their number.
     */
    public function ensureSequencePast(Workspace $workspace, string $kind, int $number): void
    {
        $this->humanId($kind, $number);

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
