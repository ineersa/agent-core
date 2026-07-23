<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Sanitized provider-quota section for the `/usage` command.
 *
 * Contains display-safe fields only — never tokens, API keys, or raw
 * provider response bodies. Independent sections degrade via {@see $error}
 * / {@see $note} without affecting sibling providers or session totals.
 */
final readonly class ProviderQuotaSectionDTO
{
    /**
     * @param list<ProviderQuotaWindowDTO> $windows
     */
    public function __construct(
        public string $title,
        public array $windows = [],
        public ?string $plan = null,
        public ?string $account = null,
        public ?float $credits = null,
        public ?int $modelCount = null,
        public ?string $note = null,
        public ?string $error = null,
        public bool $configured = true,
    ) {
    }
}
