<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Contract\Tool;

use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;

interface ToolCompletionDeliveryFenceProviderInterface
{
    public function capture(string $runId): ToolCompletionDeliveryFenceDTO;
}
