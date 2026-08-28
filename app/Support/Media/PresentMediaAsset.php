<?php

namespace App\Support\Media;

use App\Models\MediaAsset;
use App\Models\Post;
use App\Models\ScratchpadEntry;
use App\Models\Video;

final class PresentMediaAsset
{
    /**
     * @return array<string, mixed>
     */
    public function summary(MediaAsset $asset): array
    {
        return [
            'public_id' => $asset->public_id,
            'title' => $asset->title ?? $asset->original_filename,
            'description' => $asset->description,
            'kind' => $asset->kind,
            'mime' => $asset->mime,
            'bytes' => $asset->bytes,
            'width' => $asset->width,
            'height' => $asset->height,
            'created_at' => $asset->created_at?->toIso8601String(),
            'preview_url' => route('media.file', $asset),
            'source' => $this->primarySource($asset),
            'usage_count' => $this->usageCount($asset),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function detail(MediaAsset $asset): array
    {
        return [
            ...$this->summary($asset),
            'original_filename' => $asset->original_filename,
            'presentation_asset_key' => is_string($asset->meta['asset_key'] ?? null)
                ? $asset->meta['asset_key']
                : null,
            'deletable' => $this->isDeletable($asset),
            'usages' => $this->usages($asset),
        ];
    }

    private function usageCount(MediaAsset $asset): int
    {
        return count($this->usages($asset));
    }

    private function isDeletable(MediaAsset $asset): bool
    {
        if (($asset->meta['source'] ?? null) === 'presentation_library') {
            return false;
        }

        return ($asset->attachments_count ?? $asset->attachments()->count()) === 0;
    }

    /**
     * @return array{label: string, type: string}|null
     */
    private function primarySource(MediaAsset $asset): ?array
    {
        $metaSource = $asset->meta['source'] ?? null;

        if (is_string($metaSource) && $metaSource === 'presentation_library') {
            return ['label' => 'Presentation library', 'type' => 'presentation_library'];
        }

        if (is_string($metaSource) && $metaSource === 'library') {
            return ['label' => 'Library', 'type' => 'library'];
        }

        $usage = $asset->relationLoaded('attachments')
            ? $asset->attachments->first()
            : $asset->attachments()->with('attachable')->first();

        if ($usage === null) {
            return ['label' => 'Unlinked', 'type' => 'unlinked'];
        }

        return $this->usageSource($usage->attachable);
    }

    /**
     * @return list<array{label: string, href: string|null, type: string}>
     */
    private function usages(MediaAsset $asset): array
    {
        $attachments = $asset->relationLoaded('attachments')
            ? $asset->attachments
            : $asset->attachments()->with('attachable')->get();

        $fromAttachments = $attachments
            ->map(function ($attachment) {
                $source = $this->usageSource($attachment->attachable);

                return $source === null
                    ? null
                    : [...$source, 'role' => $attachment->role];
            })
            ->filter()
            ->values()
            ->all();

        $fromDecks = $this->presentationDeckUsages($asset);

        $seen = [];
        $merged = [];

        foreach ([...$fromAttachments, ...$fromDecks] as $usage) {
            $key = ($usage['href'] ?? $usage['label']).'|'.($usage['role'] ?? '');

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $merged[] = $usage;
        }

        return $merged;
    }

    /**
     * @return list<array{label: string, href: string|null, type: string, role: string}>
     */
    private function presentationDeckUsages(MediaAsset $asset): array
    {
        if (($asset->meta['source'] ?? null) !== 'presentation_library') {
            return [];
        }

        $assetKey = $asset->meta['asset_key'] ?? null;

        if (! is_string($assetKey) || $assetKey === '') {
            return [];
        }

        $paNeedle = "PA('{$assetKey}')";

        return Video::query()
            ->where('workspace_id', $asset->workspace_id)
            ->whereNotNull('deck_manifest')
            ->where(function ($query) use ($paNeedle, $assetKey) {
                $query->whereRaw('deck_manifest::text like ?', ['%'.$paNeedle.'%'])
                    ->orWhereRaw('deck_manifest::text like ?', ['%"'.$assetKey.'"%']);
            })
            ->orderByDesc('number')
            ->get()
            ->map(fn (Video $video) => [
                'label' => $video->human_id,
                'href' => route('videos.show', $video),
                'type' => 'video',
                'role' => 'presentation deck',
            ])
            ->all();
    }

    /**
     * @return array{label: string, href: string|null, type: string}|null
     */
    private function usageSource(mixed $attachable): ?array
    {
        if ($attachable instanceof ScratchpadEntry) {
            return [
                'label' => 'Scratch Pad',
                'href' => route('scratchpad.show', $attachable),
                'type' => 'scratchpad',
            ];
        }

        if ($attachable instanceof Post) {
            return [
                'label' => $attachable->human_id,
                'href' => route('posts.show', $attachable),
                'type' => 'post',
            ];
        }

        if ($attachable instanceof Video) {
            return [
                'label' => $attachable->human_id,
                'href' => route('videos.show', $attachable),
                'type' => 'video',
            ];
        }

        return null;
    }
}
