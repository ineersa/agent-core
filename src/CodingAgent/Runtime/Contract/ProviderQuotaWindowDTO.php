<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * One sanitized provider quota window for display.
 *
 * Percent-left is already clamped to [0, 100]. Reset descriptions are
 * human-readable countdowns (e.g. "in 2h") or null when unknown.
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
