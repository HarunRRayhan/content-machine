<?php

namespace App\Actions\Telegram;

use App\Actions\Ideas\CreateIdeaAction;
use App\Actions\Ideas\DropIdeaAction;
use App\Actions\Ideas\UpdateIdeaAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Scratchpad\UpdateScratchpadEntryAction;
use App\Data\Ideas\DropIdeaData;
use App\Data\Ideas\UpdateIdeaData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Data\Scratchpad\UpdateScratchpadEntryData;
use App\Models\Idea;
use App\Models\ScratchpadEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Validates and runs one action from ResolveTelegramWorkspaceActionAction
 * against the Scratch Pad and post/video ideas, always scoped to the given
 * workspace, and returns the reply text. Nothing else is reachable from
 * here. The model's arguments are untrusted: every field is checked below.
 *
 * Updates and deletes are two-step: the first message only stores the
 * pending change and asks for confirmation; it is applied only when
 * confirmPending() sees an explicit yes next. Creates run immediately. An idea "delete" is a drop (rows are never removed); a Scratch Pad delete
 * is a real delete via DeleteScratchpadEntryAction, which keeps any idea
 * made from that note and clears its source link.
 */
class ExecuteTelegramWorkspaceActionAction
{
    private const PENDING_TTL_SECONDS = 600;

    private const YES = ['yes', 'y', 'yep', 'yeah', 'confirm', 'confirmed', 'ok', 'okay', 'delete it', 'do it'];

    public function __construct(
        private readonly CaptureTextNoteAction $captureTextNote,
        private readonly UpdateScratchpadEntryAction $updateScratchpadEntry,
        private readonly DeleteScratchpadEntryAction $deleteScratchpadEntry,
        private readonly CreateIdeaAction $createIdea,
        private readonly UpdateIdeaAction $updateIdea,
        private readonly DropIdeaAction $dropIdea,
    ) {}

    /**
     * @param  array<string, mixed>  $action
     */
    public function handle(Workspace $workspace, int $chatId, array $action): string
    {
        Cache::forget($this->pendingKey($workspace, $chatId));

        try {
            return match ($action['action'] ?? null) {
                'create_scratchpad' => $this->createScratchpad($workspace, $action),
                'update_scratchpad' => $this->askToUpdateScratchpad($workspace, $chatId, $action),
                'delete_scratchpad' => $this->askToDeleteScratchpad($workspace, $chatId),
                'create_idea' => $this->saveIdea($workspace, $action),
                'update_idea' => $this->askToEditIdea($workspace, $chatId, $action),
                'delete_idea' => $this->askToDropIdea($workspace, $chatId, $action),
                'list_ideas' => $this->listIdeas($workspace, $action),
                default => "I can't do that one.",
            };
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->first() ?? "That didn't look right.";
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * Runs the stored delete when $text is an explicit yes; returns null when
     * nothing is pending or the message isn't a yes (the pending delete is
     * dropped either way, so a later "yes" can't hit a stale target).
     */
    public function confirmPending(Workspace $workspace, int $chatId, string $text): ?string
    {
        $pending = Cache::pull($this->pendingKey($workspace, $chatId));

        if (! is_array($pending) || ! in_array(Str::lower(trim($text, " \t\n.!")), self::YES, true)) {
            return null;
        }

        try {
            return match ($pending['type']) {
                'update_scratchpad', 'delete_scratchpad' => $this->confirmScratchpad($workspace, $pending),
                default => $this->confirmIdea($workspace, $pending),
            };
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function confirmScratchpad(Workspace $workspace, array $pending): string
    {
        $entry = ScratchpadEntry::query()
            ->where('workspace_id', $workspace->id)
            ->where('public_id', $pending['id'])
            ->first();

        if ($entry === null) {
            return 'That note is already gone.';
        }

        if ($pending['type'] === 'update_scratchpad') {
            $this->updateScratchpadEntry->handle($entry, new UpdateScratchpadEntryData(title: null, body: $pending['changes']['body'], language: null));

            return 'Updated the note.';
        }

        $this->deleteScratchpadEntry->handle($entry);

        return 'Deleted the note.';
    }

    /**
     * @param  array<string, mixed>  $pending
     */
    private function confirmIdea(Workspace $workspace, array $pending): string
    {
        $idea = $this->findIdea($workspace, $pending['id']);

        if ($pending['type'] === 'update_idea') {
            $changes = $pending['changes'];

            $this->updateIdea->handle($idea, new UpdateIdeaData(
                title: $changes['title'] ?? $idea->title,
                score: $changes['score'] ?? $idea->score,
                trend: $idea->trend,
                rationale: $changes['rationale'] ?? $idea->rationale,
                body: $changes['body'] ?? $idea->body,
            ));

            return "Updated {$idea->human_id}.";
        }

        $this->dropIdea->handle($idea, new DropIdeaData('Dropped from Telegram.'));

        return "Dropped {$idea->human_id}.";
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function createScratchpad(Workspace $workspace, array $action): string
    {
        $body = $this->text($action, 'body', 20000, required: true);

        $this->captureTextNote->handle($workspace, null, CaptureTextNoteData::fromTelegram($body));

        return 'Saved to the Scratch Pad.';
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function askToUpdateScratchpad(Workspace $workspace, int $chatId, array $action): string
    {
        $entry = $this->latestScratchpad($workspace);
        $body = $this->text($action, 'body', 20000, required: true);
        $this->storePending($workspace, $chatId, 'update_scratchpad', $entry->public_id, ['body' => $body]);

        return 'Replace your latest note with this? Reply "yes" to confirm.'."\n\n".Str::limit($body, 120);
    }

    private function askToDeleteScratchpad(Workspace $workspace, int $chatId): string
    {
        $entry = $this->latestScratchpad($workspace);
        $this->storePending($workspace, $chatId, 'delete_scratchpad', $entry->public_id);

        return 'Delete this note? Reply "yes" to confirm.'."\n\n".Str::limit($entry->title ?? $entry->body ?? '(no preview)', 120);
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function saveIdea(Workspace $workspace, array $action): string
    {
        $kind = $action['kind'] ?? null;

        if (! in_array($kind, ['post', 'video', 'both'], true)) {
            throw ValidationException::withMessages(['kind' => 'Is it a post idea or a video idea?']);
        }

        $fields = [
            'title' => $this->text($action, 'title', 255, required: true),
            'rationale' => $this->text($action, 'rationale', 2000),
            'body' => $this->text($action, 'body', 20000),
        ];

        $ideas = collect($kind === 'both' ? ['post', 'video'] : [$kind])
            ->map(fn (string $k) => $this->createIdea->handle($workspace, ['kind' => $k] + $fields));

        return 'Saved '.$ideas->pluck('human_id')->implode(' and ').": {$fields['title']}";
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function askToEditIdea(Workspace $workspace, int $chatId, array $action): string
    {
        $idea = $this->findIdea($workspace, $action['id'] ?? null);

        $score = $action['score'] ?? null;

        if ($score !== null && (! is_int($score) || $score < 0 || $score > 1000)) {
            throw ValidationException::withMessages(['score' => 'Score must be a whole number from 0 to 1000.']);
        }

        $changes = array_filter([
            'title' => $this->text($action, 'title', 255),
            'rationale' => $this->text($action, 'rationale', 2000),
            'body' => $this->text($action, 'body', 20000),
            'score' => $score,
        ], fn ($value) => $value !== null);

        if ($changes === []) {
            throw new RuntimeException('What should I change on '.$idea->human_id.'?');
        }

        $this->storePending($workspace, $chatId, 'update_idea', $idea->human_id, $changes);

        return "Update {$idea->human_id} ({$idea->title}): ".implode(', ', array_keys($changes)).'? Reply "yes" to confirm.';
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function askToDropIdea(Workspace $workspace, int $chatId, array $action): string
    {
        $idea = $this->findIdea($workspace, $action['id'] ?? null);
        $this->storePending($workspace, $chatId, 'delete_idea', $idea->human_id);

        return "Drop {$idea->human_id}: {$idea->title}? Reply \"yes\" to confirm.";
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function listIdeas(Workspace $workspace, array $action): string
    {
        $query = Idea::query()
            ->where('workspace_id', $workspace->id)
            ->where('status', 'open')
            ->whereIn('kind', ['post', 'video'])
            ->orderByDesc('id')
            ->limit(10);

        if (in_array($action['kind'] ?? null, ['post', 'video'], true)) {
            $query->where('kind', $action['kind']);
        }

        $ideas = $query->get();

        return $ideas->isEmpty()
            ? 'No open ideas.'
            : $ideas->map(fn (Idea $idea) => "{$idea->human_id} · {$idea->title}")->implode("\n");
    }

    private function latestScratchpad(Workspace $workspace): ScratchpadEntry
    {
        return ScratchpadEntry::query()
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('captured_at')
            ->first() ?? throw new RuntimeException('No Scratch Pad notes yet.');
    }

    private function findIdea(Workspace $workspace, mixed $humanId): Idea
    {
        $idea = is_string($humanId)
            ? Idea::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('kind', ['post', 'video'])
                ->where('human_id', Str::upper(trim($humanId)))
                ->first()
            : null;

        return $idea ?? throw new RuntimeException("I couldn't find that idea.");
    }

    /**
     * @param  array<string, mixed>  $action
     */
    private function text(array $action, string $field, int $max, bool $required = false): ?string
    {
        $value = $action[$field] ?? null;
        $value = is_string($value) ? trim($value) : '';

        if ($value === '') {
            return $required
                ? throw ValidationException::withMessages([$field => "I need a {$field} for that."])
                : null;
        }

        return Str::limit($value, $max, '');
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function storePending(Workspace $workspace, int $chatId, string $type, string $id, array $changes = []): void
    {
        Cache::put($this->pendingKey($workspace, $chatId), ['type' => $type, 'id' => $id, 'changes' => $changes], self::PENDING_TTL_SECONDS);
    }

    private function pendingKey(Workspace $workspace, int $chatId): string
    {
        return "telegram:pending-delete:{$workspace->id}:{$chatId}";
    }
}
