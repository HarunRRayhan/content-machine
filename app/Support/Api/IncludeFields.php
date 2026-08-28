<?php

namespace App\Support\Api;

use Illuminate\Http\Request;

/**
 * Parses ?include= for API list endpoints. Default is slim (heavy fields
 * omitted). Pass include=full or a comma list of field names to opt in.
 */
final class IncludeFields
{
    /**
     * @param  list<string>  $fields
     */
    private function __construct(private readonly array $fields) {}

    public static function full(): self
    {
        return new self(['*']);
    }

    public static function fromRequest(Request $request): self
    {
        $raw = trim($request->string('include')->toString());

        if ($raw === '' || $raw === '0') {
            return new self([]);
        }

        if ($raw === 'full' || $raw === '*') {
            return self::full();
        }

        $fields = array_values(array_unique(array_filter(array_map(
            static fn (string $field): string => trim($field),
            explode(',', $raw),
        ), static fn (string $field): bool => $field !== '')));

        return new self($fields);
    }

    public function wants(string $field): bool
    {
        return in_array('*', $this->fields, true)
            || in_array($field, $this->fields, true);
    }

    public function isSlim(): bool
    {
        return $this->fields === [];
    }
}
