<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Combined provider-quota report for OpenAI Codex and z.ai.
 *
 * Session totals are assembled by the TUI from {@see \Ineersa\Tui\Runtime\UsageProjection};
 * this DTO only carries network/provider probe results.
 */
final readonly class ProviderQuotaReportDTO
{
    public function __construct(
        public ProviderQuotaSectionDTO $openaiCodex,
        public ProviderQuotaSectionDTO $zai,
    ) {
    }
}
