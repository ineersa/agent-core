<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Provider-quota report for `/usage`.
 *
 * Session totals are assembled by the TUI from {@see \Ineersa\Tui\Runtime\UsageProjection}.
 * An empty {@see $sections} list means no configured providers were probed.
 */
final readonly class ProviderQuotaReportDTO
{
    /**
     * @param list<ProviderQuotaSectionDTO> $sections
     */
    public function __construct(
        public array $sections,
    ) {
    }
}
