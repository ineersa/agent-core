<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Sanitized provider-quota section for the `/usage` command.
 *
 * Display-safe fields only — never tokens, API keys, or raw response bodies.
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
        public ?int $modelCount = null,
        public ?string $note = null,
        public ?string $error = null,
    ) {
    }
}
