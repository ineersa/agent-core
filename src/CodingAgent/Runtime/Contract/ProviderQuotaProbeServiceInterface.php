<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Runtime\Contract;

/**
 * Read-only boundary for probing provider quota endpoints.
 *
 * Implemented by CodingAgent infrastructure. TUI code depends on this
 * interface only — never on auth storage, API keys, or HTTP clients.
 *
 * Probes are independently degradable: one provider failure must not
 * suppress the other provider's section.
 */
interface ProviderQuotaProbeServiceInterface
{
    public function probe(): ProviderQuotaReportDTO;
}
