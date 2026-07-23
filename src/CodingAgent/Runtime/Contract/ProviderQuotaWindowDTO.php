<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * One sanitized provider quota window for display.
 */
final readonly class ProviderQuotaWindowDTO
{
    public function __construct(
        public string $label,
        public float $percentLeft,
        public ?string $resetDescription = null,
    ) {
    }
}
