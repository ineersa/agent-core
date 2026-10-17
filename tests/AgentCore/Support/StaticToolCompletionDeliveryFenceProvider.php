<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Support;

use Ineersa\AgentCore\Contract\Tool\ToolCompletionDeliveryFenceProviderInterface;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;

final readonly class StaticToolCompletionDeliveryFenceProvider implements ToolCompletionDeliveryFenceProviderInterface
{
    public function __construct(private ToolCompletionDeliveryFenceDTO $fence = new ToolCompletionDeliveryFenceDTO(0, 0, 0))
    {
    }

    public function capture(string $runId): ToolCompletionDeliveryFenceDTO
    {
        return $this->fence;
    }
}
