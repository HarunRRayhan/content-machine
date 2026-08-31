<?php

namespace App\Support\Media;

/**
 * Fixed catalog of standalone-post design templates (A–F).
 * Source of truth for labels; posts.template stores the letter.
 */
final readonly class PostDesignTemplate
{
    public const LETTERS = ['A', 'B', 'C', 'D', 'E', 'F'];

    public function __construct(
        public string $letter,
        public string $slug,
        public string $name,
        public string $description,
        public string $visualIdentity,
        public ?string $provenOnHumanId = null,
        public ?string $provenOnLabel = null,
    ) {}

    /**
     * @return list<self>
     */
    public static function all(): array
    {
        return array_map(
            static fn (string $letter) => self::from($letter),
            self::LETTERS,
        );
    }

    public static function from(string $letter): self
    {
        $letter = strtoupper(trim($letter));

        return match ($letter) {
            'A' => new self(
                letter: 'A',
                slug: 'template-a-light-data-driven',
                name: 'Light data-driven',
                description: 'Cream paper background, soft dual-color washes, bordered white cards, orange highlighter headlines, large real-logo hero.',
                visualIdentity: 'Light / data-driven',
                provenOnHumanId: 'P-29',
                provenOnLabel: 'AWS trillion-dollar bug',
            ),
            'B' => new self(
                letter: 'B',
                slug: 'template-b-dark-atmospheric',
                name: 'Dark atmospheric',
                description: 'Deep navy-to-black gradient, amber corner glow, minimal chrome, amber highlighter headlines.',
                visualIdentity: 'Dark / atmospheric',
                provenOnHumanId: 'P-30',
                provenOnLabel: 'SQLite WAL bug',
            ),
            'C' => new self(
                letter: 'C',
                slug: 'template-c-blueprint-paper',
                name: 'Blueprint paper',
                description: 'Warm sepia graph-paper canvas, blueprint grid, flat white boxes, outlined badges, flowchart diagrams.',
                visualIdentity: 'Light / technical',
                provenOnHumanId: 'P-31',
                provenOnLabel: 'Webhook status code',
            ),
            'D' => new self(
                letter: 'D',
                slug: 'template-d-split-comparison',
                name: 'Split comparison',
                description: 'Dark workspace background, vertical center split, two-column compare (name / blurb / tag).',
                visualIdentity: 'Split comparison',
                provenOnHumanId: 'P-63',
                provenOnLabel: 'Database pairs',
            ),
            'E' => new self(
                letter: 'E',
                slug: 'template-e-cheatsheet-doodle',
                name: 'Cheatsheet doodle',
                description: 'Off-white paper texture, yellow outline titles, number badges, doodle diagrams.',
                visualIdentity: 'Cheatsheet / doodle',
                provenOnHumanId: 'P-64',
                provenOnLabel: 'HTTP status codes',
            ),
            'F' => new self(
                letter: 'F',
                slug: 'template-f-product-showcase',
                name: 'Product showcase',
                description: 'Dark lifestyle background, floating logos, glass terminal sample, centered CTA avatar.',
                visualIdentity: 'Product showcase',
                provenOnHumanId: 'P-65',
                provenOnLabel: 'CLI tools',
            ),
            default => throw new \InvalidArgumentException("Unknown post design template [{$letter}]."),
        };
    }

    public static function tryFrom(?string $letter): ?self
    {
        if ($letter === null || trim($letter) === '') {
            return null;
        }

        try {
            return self::from($letter);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'letter' => $this->letter,
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'visual_identity' => $this->visualIdentity,
            'proven_on_human_id' => $this->provenOnHumanId,
            'proven_on_label' => $this->provenOnLabel,
            'label' => "Template {$this->letter}",
        ];
    }
}
