<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;

/**
 * Deterministic provider-quota stub for APP_ENV=test.
 */
final class FakeProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    public function probe(): ProviderQuotaReportDTO
    {
        return new ProviderQuotaReportDTO([
            new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                windows: [
                    new ProviderQuotaWindowDTO('Codex (5h)', 83.0, 'in 2h'),
                ],
                plan: 'pro',
                account: 'user@example.com',
            ),
            new ProviderQuotaSectionDTO(
                title: 'z.ai',
                windows: [
                    new ProviderQuotaWindowDTO('Tokens (250/1,000)', 75.0, 'in 1h'),
                ],
                modelCount: 3,
            ),
        ]);
    }
}
