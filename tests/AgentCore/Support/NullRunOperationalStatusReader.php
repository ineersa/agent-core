<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\RunOperationalStatusDTO;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;

/** @internal */
final readonly class NullRunOperationalStatusReader implements RunOperationalStatusReaderInterface
{
    public function findOperationalStatus(string $runId): ?RunOperationalStatusDTO
    {
        return null;
    }
}
