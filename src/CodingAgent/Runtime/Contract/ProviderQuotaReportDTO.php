<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

use Ineersa\Tui\Runtime\UsageProjection;

/**
 * Provider-quota report for `/usage`.
 *
 * Session totals are assembled by the TUI from {@see UsageProjection}.
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
