<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use PHPUnit\Framework\TestCase;

final class CurrentToolCallDTOTest extends TestCase
{
    public function testBoundedDescriptorChangesOnlyLifecycleStatus(): void
    {
        $descriptor = new CurrentToolCallDTO(
            ToolBatchIdentity::fromTurnAndStep(4, 'step-4'),
            'tool-call-1',
            2,
            RunOperationalToolCallStatusEnum::Pending,
            1,
        );

        $running = $descriptor->withStatus(RunOperationalToolCallStatusEnum::Running);

        $this->assertSame($descriptor->batchId, $running->batchId);
        $this->assertSame('tool-call-1', $running->toolCallId);
        $this->assertSame(2, $running->orderIndex);
        $this->assertSame(1, $running->attempt);
        $this->assertSame(RunOperationalToolCallStatusEnum::Running, $running->status);
        $this->assertSame($descriptor->batchId, ToolBatchIdentity::fromTurnAndStep(4, 'step-4'));
    }
}
