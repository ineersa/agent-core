<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Tool\Completion;

use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryFenceDTO;
use PHPUnit\Framework\TestCase;

final class ToolCompletionDeliveryFenceDTOTest extends TestCase
{
    public function testGenerationsAreNonNegativeAndIndependent(): void
    {
        $fence = new ToolCompletionDeliveryFenceDTO(2, 3, 4);
        $this->assertSame([2, 3, 4], [$fence->wakeGeneration, $fence->historyGeneration, $fence->controllerGeneration]);
        $this->expectException(\InvalidArgumentException::class);
        new ToolCompletionDeliveryFenceDTO(0, -1, 0);
    }
}
