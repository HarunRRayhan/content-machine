<?php

namespace App\Support\Media;

/**
 * Static snapshot of the personal-content template catalog, refreshed alongside
 * catalog changes. It is not loaded or synchronized automatically at runtime.
 * Archived entries stay resolvable so existing post metadata keeps its identity.
 */
final readonly class PostDesignTemplate
{
    /**
     * Catalog snapshot. `directory` mirrors catalog.json, while `name` and the
     * descriptive fields are sourced from each package's README.
     *
     * @var array<string, array{
     *     status: 'active'|'archived',
     *     directory: string,
     *     name: string,
     *     description: string,
     *     visual_identity: string,
     *     proven_on_human_id: ?string,
     *     proven_on_label: ?string,
     *     successor?: string
     * }>
     */
    private const CATALOG = [
        'A' => [
            'status' => 'active',
            'directory' => 'template-a-light-data-driven',
            'name' => 'Light data-driven',
            'description' => 'Cream paper background, soft dual-color washes, bordered white cards, orange highlighter headlines, large real-logo hero.',
            'visual_identity' => 'Light / data-driven',
            'proven_on_human_id' => 'P-29',
            'proven_on_label' => 'AWS trillion-dollar bug',
        ],
        'B' => [
            'status' => 'active',
            'directory' => 'template-b-dark-atmospheric',
            'name' => 'Dark atmospheric',
            'description' => 'Deep navy-to-black gradient, amber corner glow, minimal chrome, amber highlighter headlines.',
            'visual_identity' => 'Dark / atmospheric',
            'proven_on_human_id' => 'P-30',
            'proven_on_label' => 'SQLite WAL bug',
        ],
        'C' => [
            'status' => 'active',
            'directory' => 'template-c-blueprint-paper',
            'name' => 'Blueprint paper',
            'description' => 'Warm sepia graph-paper canvas, blueprint grid, flat white boxes, outlined badges, flowchart diagrams.',
            'visual_identity' => 'Light / technical',
            'proven_on_human_id' => 'P-31',
            'proven_on_label' => 'Webhook status code',
        ],
        'D' => [
            'status' => 'active',
            'directory' => 'template-d-split-comparison',
            'name' => 'Split comparison',
            'description' => 'Dark workspace background, vertical center split, two-column compare (name / blurb / tag).',
            'visual_identity' => 'Split comparison',
            'proven_on_human_id' => 'P-63',
            'proven_on_label' => 'Database pairs',
        ],
        'E' => [
            'status' => 'archived',
            'directory' => 'archived/template-e-cheatsheet-doodle',
            'name' => 'Cheatsheet doodle',
            'description' => 'Off-white paper texture, yellow outline titles, number badges, doodle diagrams.',
            'visual_identity' => 'Cheatsheet / doodle',
            'proven_on_human_id' => 'P-64',
            'proven_on_label' => 'HTTP status codes',
            'successor' => 'G',
        ],
        'F' => [
            'status' => 'active',
            'directory' => 'template-f-product-showcase',
            'name' => 'Product showcase',
            'description' => 'Dark lifestyle background, floating logos, glass terminal sample, centered CTA avatar.',
            'visual_identity' => 'Product showcase',
            'proven_on_human_id' => 'P-65',
            'proven_on_label' => 'CLI tools',
        ],
        'G' => [
            'status' => 'active',
            'directory' => 'template-g-handwritten-explainer',
            'name' => 'Handwritten Blue Explainer',
            'description' => 'White-paper hand-drawn explainer with cobalt emphasis, pastel cards, systems doodles, command panels, and paired cheatsheets inherited from E.',
            'visual_identity' => 'Handwritten / technical explainer',
            'proven_on_human_id' => null,
            'proven_on_label' => 'NSLOOKUP reference carousel',
        ],
        'H' => [
            'status' => 'active',
            'directory' => 'template-h-dark-systems-explainer',
            'name' => 'Dark Systems Explainer',
            'description' => 'Square dark technical explainer with cream headlines, orange warnings, teal verification, architecture diagrams, and inherited field and output-gate patterns.',
            'visual_identity' => 'Dark / systems explainer',
            'proven_on_human_id' => null,
            'proven_on_label' => 'Redis reference carousel',
        ],
        'I' => [
            'status' => 'archived',
            'directory' => 'archived/template-i-linux-security-explainer',
            'name' => 'Linux Security Files',
            'description' => 'Black, color-coded security explainer for Linux internals, incident-response checks, file formats, permissions, and security comparisons.',
            'visual_identity' => 'Dark / Linux security',
            'proven_on_human_id' => null,
            'proven_on_label' => null,
            'successor' => 'H',
        ],
        'J' => [
            'status' => 'archived',
            'directory' => 'archived/template-j-trajectory-output-gate',
            'name' => 'Trajectory Output Gate',
            'description' => 'Dark technical visual system for processes that explore freely, then pass a final hard check before output is accepted.',
            'visual_identity' => 'Dark / output gate',
            'proven_on_human_id' => 'P-84',
            'proven_on_label' => null,
            'successor' => 'H',
        ],
    ];

    public function __construct(
        public string $letter,
        public string $slug,
        public string $name,
        public string $description,
        public string $visualIdentity,
        public string $directory,
        public string $status,
        public ?string $provenOnHumanId = null,
        public ?string $provenOnLabel = null,
        public ?string $successor = null,
    ) {}

    /** @return list<string> */
    public static function letters(): array
    {
        return array_keys(self::CATALOG);
    }

    /** @return list<string> */
    public static function apiLetters(): array
    {
        return [...self::letters(), ...array_map('strtolower', self::letters())];
    }

    /** @return list<string> */
    public static function activeLetters(): array
    {
        return array_keys(array_filter(self::CATALOG, static fn (array $template) => $template['status'] === 'active'));
    }

    /** @return list<self> */
    public static function all(): array
    {
        return array_map(static fn (string $letter) => self::from($letter), self::activeLetters());
    }

    public static function from(string $letter): self
    {
        $letter = strtoupper(trim($letter));
        $template = self::CATALOG[$letter] ?? null;

        if ($template === null) {
            throw new \InvalidArgumentException("Unknown post design template [{$letter}].");
        }

        return new self(
            letter: $letter,
            slug: basename($template['directory']),
            name: $template['name'],
            description: $template['description'],
            visualIdentity: $template['visual_identity'],
            directory: $template['directory'],
            status: $template['status'],
            provenOnHumanId: $template['proven_on_human_id'],
            provenOnLabel: $template['proven_on_label'],
            successor: $template['successor'] ?? null,
        );
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'letter' => $this->letter,
            'slug' => $this->slug,
            'directory' => $this->directory,
            'status' => $this->status,
            'successor' => $this->successor,
            'name' => $this->name,
            'description' => $this->description,
            'visual_identity' => $this->visualIdentity,
            'proven_on_human_id' => $this->provenOnHumanId,
            'proven_on_label' => $this->provenOnLabel,
            'label' => "Template {$this->letter}",
            'preview_url' => asset("images/templates/{$this->slug}.png"),
        ];
    }
}
