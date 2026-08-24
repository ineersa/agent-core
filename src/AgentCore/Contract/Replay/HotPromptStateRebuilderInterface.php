<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Replay;

use Ineersa\AgentCore\Domain\Run\PromptState;
use Ineersa\AgentCore\Domain\Run\RunState;

interface HotPromptStateRebuilderInterface
{
    public function rebuildHotPromptState(RunState $state): PromptState;
}
