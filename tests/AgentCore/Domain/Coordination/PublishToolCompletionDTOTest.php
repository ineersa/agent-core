<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Coordination;

use Ineersa\AgentCore\Domain\Coordination\DispatchCoordinationMessageDTO;
use Ineersa\AgentCore\Domain\Coordination\PublishToolCompletionDTO;
use Ineersa\AgentCore\Domain\Message\ReceiveToolCompletion;
use PHPUnit\Framework\TestCase;

final class PublishToolCompletionDTOTest extends TestCase
{
    public function testBindingUsesAllocatedOrdinalInsteadOfPredictedSequence(): void
    {
        $bound = (new PublishToolCompletionDTO('run', 3, 'message', str_repeat('a', 64), 1))->bindCanonicalSequences([106, 110]);
        $this->assertInstanceOf(DispatchCoordinationMessageDTO::class, $bound);
        $this->assertInstanceOf(ReceiveToolCompletion::class, $bound->message);
        $this->assertSame(110, $bound->message->reference->sourceEvent->seq);
        $this->assertSame(3, $bound->message->reference->sourceEvent->turnNo);
        $this->assertSame('run', $bound->message->runId());
    }

    public function testMissingOrdinalFailsBeforePublication(): void
    {
        $this->expectException(\RuntimeException::class);
        (new PublishToolCompletionDTO('run', 3, 'message', str_repeat('a', 64), 1))->bindCanonicalSequences([106]);
    }
}
