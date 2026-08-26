<?php

namespace App\Support\Mcp;

use App\Actions\Ideas\UpdateIdeaAction;
use App\Actions\Scratchpad\CaptureScratchpadLinkAction;
use App\Actions\Scratchpad\CaptureTextNoteAction;
use App\Actions\Scratchpad\DeleteScratchpadEntryAction;
use App\Actions\Scratchpad\TriageScratchpadEntryAction;
use App\Actions\Scratchpad\UpdateScratchpadEntryAction;
use App\Data\Ideas\UpdateIdeaData;
use App\Data\Scratchpad\CaptureScratchpadLinkData;
use App\Data\Scratchpad\CaptureTextNoteData;
use App\Data\Scratchpad\TriageScratchpadEntryData;
use App\Data\Scratchpad\UpdateScratchpadEntryData;
use App\Http\Resources\V1\IdeaResource;
use App\Http\Resources\V1\ScratchpadEntryResource;
use App\Models\Idea;
use App\Models\ScratchpadEntry;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentApiToken;
use RuntimeException;

/**
 * Runs one MCP tools/call against the same Actions the JSON API uses.
 */
final class McpToolDispatcher
{
    public function __construct(
        private readonly CurrentApiToken $currentApiToken,
        private readonly CaptureTextNoteAction $captureTextNoteAction,
        private readonly CaptureScratchpadLinkAction $captureScratchpadLinkAction,
        private readonly UpdateScratchpadEntryAction $updateScratchpadEntryAction,
        private readonly DeleteScratchpadEntryAction $deleteScratchpadEntryAction,
        private readonly TriageScratchpadEntryAction $triageScratchpadEntryAction,
        private readonly UpdateIdeaAction $updateIdeaAction,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>|list<mixed>
     */
    public function handle(string $name, array $arguments): array
    {
        $tool = McpToolCatalog::find($name);

        if ($tool === null) {
            throw new RuntimeException("Unknown tool: {$name}");
        }

        $token = $this->currentApiToken->get();

        if ($token === null || ! $token->hasAbility($tool['ability'])) {
            throw new RuntimeException("Token is missing the [{$tool['ability']}] ability.");
        }

        $workspace = Workspace::current();

        if ($workspace === null) {
            throw new RuntimeException('No current workspace.');
        }

        return match ($name) {
            'list_scratchpad' => $this->listScratchpad($arguments),
            'get_scratchpad' => $this->presentEntry($this->findEntry($this->stringArg($arguments, 'public_id'))),
            'capture_note' => $this->presentEntry($this->captureTextNoteAction->handle(
                $workspace,
                $this->actor(),
                CaptureTextNoteData::fromApi($this->stringArg($arguments, 'body')),
            )),
            'capture_link' => $this->presentEntry($this->captureScratchpadLinkAction->handle(
                $workspace,
                $this->actor(),
                CaptureScratchpadLinkData::fromApi($this->stringArg($arguments, 'url')),
            )),
            'update_scratchpad' => $this->updateScratchpad($arguments),
            'delete_scratchpad' => $this->deleteScratchpad($arguments),
            'triage_scratchpad' => $this->triageScratchpad($arguments),
            'list_ideas' => $this->listIdeas($arguments),
            'get_idea' => $this->presentIdea($this->findIdea($this->stringArg($arguments, 'human_id'))),
            'update_idea' => $this->updateIdea($arguments),
            default => throw new RuntimeException("Unknown tool: {$name}"),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listScratchpad(array $arguments): array
    {
        $status = $this->optionalString($arguments, 'status') ?? 'new';
        $kind = $this->optionalString($arguments, 'kind');

        $entries = ScratchpadEntry::query()
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->with(['attachments.mediaAsset', 'transcriptions'])
            ->orderByDesc('captured_at')
            ->limit(50)
            ->get();

        $presented = [];

        foreach ($entries as $entry) {
            $presented[] = $this->presentEntry($entry);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function updateScratchpad(array $arguments): array
    {
        $entry = $this->findEntry($this->stringArg($arguments, 'public_id'));

        $data = new UpdateScratchpadEntryData(
            title: $this->optionalString($arguments, 'title'),
            body: $this->optionalString($arguments, 'body'),
            language: $this->optionalString($arguments, 'language'),
        );

        if ($data->changes() === []) {
            throw new RuntimeException('Send at least one of title, body, language.');
        }

        return $this->presentEntry($this->updateScratchpadEntryAction->handle($entry, $data));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function deleteScratchpad(array $arguments): array
    {
        $entry = $this->findEntry($this->stringArg($arguments, 'public_id'));
        $publicId = $entry->public_id;
        $this->deleteScratchpadEntryAction->handle($entry);

        return ['deleted' => true, 'public_id' => $publicId];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function triageScratchpad(array $arguments): array
    {
        $actor = $this->actor();

        if ($actor === null) {
            throw new RuntimeException('This token has no creator, so it cannot triage.');
        }

        $entry = $this->findEntry($this->stringArg($arguments, 'public_id'));
        $score = $arguments['score'] ?? null;

        $data = new TriageScratchpadEntryData(
            target: $this->stringArg($arguments, 'target'),
            title: $this->optionalString($arguments, 'title'),
            score: is_numeric($score) ? (int) $score : null,
            trend: $this->optionalString($arguments, 'trend'),
            rationale: $this->optionalString($arguments, 'rationale'),
            dropReason: $this->optionalString($arguments, 'drop_reason'),
        );

        return $this->presentEntry($this->triageScratchpadEntryAction->handle($entry, $actor, $data));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return list<array<string, mixed>>
     */
    private function listIdeas(array $arguments): array
    {
        $kind = $this->optionalString($arguments, 'kind');
        $status = $this->optionalString($arguments, 'status');

        $ideas = Idea::query()
            ->when($kind !== null, fn ($query) => $query->where('kind', $kind))
            ->when($status !== null, fn ($query) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        $presented = [];

        foreach ($ideas as $idea) {
            $presented[] = $this->presentIdea($idea);
        }

        return $presented;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function updateIdea(array $arguments): array
    {
        $idea = $this->findIdea($this->stringArg($arguments, 'human_id'));
        $score = $arguments['score'] ?? null;

        $data = new UpdateIdeaData(
            title: $this->stringArg($arguments, 'title'),
            score: is_numeric($score) ? (int) $score : $idea->score,
            trend: $this->optionalString($arguments, 'trend') ?? $idea->trend,
            rationale: $this->optionalString($arguments, 'rationale') ?? $idea->rationale,
            body: $this->optionalString($arguments, 'body') ?? $idea->body,
        );

        return $this->presentIdea($this->updateIdeaAction->handle($idea, $data));
    }

    /**
     * @return array<string, mixed>
     */
    private function presentEntry(ScratchpadEntry $entry): array
    {
        $entry->loadMissing(['attachments.mediaAsset', 'transcriptions']);

        /** @var array<string, mixed> $payload */
        $payload = (new ScratchpadEntryResource($entry))->resolve();

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function presentIdea(Idea $idea): array
    {
        /** @var array<string, mixed> $payload */
        $payload = (new IdeaResource($idea))->resolve();

        return $payload;
    }

    private function findEntry(string $publicId): ScratchpadEntry
    {
        $entry = ScratchpadEntry::query()->where('public_id', $publicId)->first();

        if ($entry === null) {
            throw new RuntimeException('Scratch pad entry not found.');
        }

        return $entry;
    }

    private function findIdea(string $humanId): Idea
    {
        $idea = Idea::query()->where('human_id', $humanId)->first();

        if ($idea === null) {
            throw new RuntimeException('Idea not found.');
        }

        return $idea;
    }

    private function actor(): ?User
    {
        return $this->currentApiToken->get()?->createdBy;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function stringArg(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new RuntimeException("Missing required argument: {$key}");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function optionalString(array $arguments, string $key): ?string
    {
        $value = $arguments[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
