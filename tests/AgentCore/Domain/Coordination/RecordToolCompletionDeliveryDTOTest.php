<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Coordination;

use Ineersa\AgentCore\Domain\Coordination\RecordToolCompletionDeliveryDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\RetainedToolCompletionSourceEventReference;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionDeliveryReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\Completion\ToolCompletionRecipientDTO;
use PHPUnit\Framework\TestCase;

final class RecordToolCompletionDeliveryDTOTest extends TestCase
{
    public function testReceiptLocationBindsOnlyOnce(): void
    {
        $reference = new ToolCompletionDeliveryReferenceDTO('run', 'message', new RetainedToolCompletionSourceEventReference('run', 106, 2), str_repeat('a', 64));
        $action = new RecordToolCompletionDeliveryDTO(new ToolCompletionRecipientDTO($reference, 3), receiptEventOrdinal: 1);
        $bound = $action->bindCanonicalSequences([107, 109]);
        $this->assertInstanceOf(RecordToolCompletionDeliveryDTO::class, $bound);
        $this->assertSame(109, $bound->delivery->receivedSequence);
        $this->assertSame(106, $bound->delivery->reference->sourceEvent->seq);
        $this->assertSame($bound, $bound->bindCanonicalSequences([107, 109]));
        $this->expectException(\RuntimeException::class);
        $bound->bindCanonicalSequences([107, 999]);
    }

    public function testMissingReceiptOrdinalFailsClosed(): void
    {
        $reference = new ToolCompletionDeliveryReferenceDTO('run', 'message', new RetainedToolCompletionSourceEventReference('run', 1, 2), str_repeat('a', 64));
        $action = new RecordToolCompletionDeliveryDTO(new ToolCompletionRecipientDTO($reference, 3), receiptEventOrdinal: 1);
        $this->expectException(\RuntimeException::class);
        $action->bindCanonicalSequences([9]);
    }
}
