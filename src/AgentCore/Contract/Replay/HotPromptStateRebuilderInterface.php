<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Replay;

use Ineersa\AgentCore\Domain\Run\PromptState;

interface HotPromptStateRebuilderInterface
{
    public function rebuildHotPromptState(string $runId): PromptState;
}
