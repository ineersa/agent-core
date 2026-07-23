<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\ProviderQuota;

use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaProbeServiceInterface;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaReportDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaSectionDTO;
use Ineersa\CodingAgent\Runtime\Contract\ProviderQuotaWindowDTO;

/**
 * Deterministic provider-quota stub for APP_ENV=test.
 *
 * Wired by config/services_test.yaml so TUI E2E never touches real auth files
 * or provider networks while still exercising real /usage routing/rendering.
 */
final class FakeProviderQuotaProbeService implements ProviderQuotaProbeServiceInterface
{
    public function probe(): ProviderQuotaReportDTO
    {
        return new ProviderQuotaReportDTO(
            openaiCodex: new ProviderQuotaSectionDTO(
                title: 'OpenAI Codex',
                windows: [
                    new ProviderQuotaWindowDTO('Codex (5h)', 83.0, 'in 2h'),
                ],
                plan: 'pro',
                account: 'user@example.com',
            ),
            zai: new ProviderQuotaSectionDTO(
                title: 'z.ai',
                windows: [
                    new ProviderQuotaWindowDTO('Tokens (250/1,000)', 75.0, 'in 1h'),
                ],
                modelCount: 3,
            ),
        );
    }
}
