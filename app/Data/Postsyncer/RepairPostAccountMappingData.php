<?php

namespace App\Data\Postsyncer;

use App\Http\Requests\Posts\RepairPostAccountMappingRequest;

final readonly class RepairPostAccountMappingData
{
    public function __construct(
        public string $language,
        public string $platform,
        public string $fromAccountId,
        public string $toAccountId,
    ) {}

    public static function fromRequest(RepairPostAccountMappingRequest $request): self
    {
        $validated = $request->validated();

        return new self(
            language: (string) $validated['language'],
            platform: strtolower((string) $validated['platform']),
            fromAccountId: (string) $validated['from_account_id'],
            toAccountId: (string) $validated['to_account_id'],
        );
    }
}
