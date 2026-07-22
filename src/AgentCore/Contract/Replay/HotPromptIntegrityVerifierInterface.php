<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Replay;

use Ineersa\AgentCore\Application\Dto\ReplayIntegrity;

interface HotPromptIntegrityVerifierInterface
{
    public function verifyIntegrity(string $runId): ReplayIntegrity;
}
