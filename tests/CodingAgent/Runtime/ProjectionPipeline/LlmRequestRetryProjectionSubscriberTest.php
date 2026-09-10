<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\ProjectionPipeline;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlock;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptBlockKindEnum;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\LlmRequestRetryProjectionSubscriber;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjectionEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;

final class LlmRequestRetryProjectionSubscriberTest extends TestCase
{
    public function testRetryingRemovesActiveStreamingBlocks(): void
    {
        $state = new TranscriptProjectionState();
        $state->addBlock(new TranscriptBlock(
            id: 'stream-1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'partial',
            streaming: true,
        ));
        $state->addBlock(new TranscriptBlock(
            id: 'done-1',
            kind: TranscriptBlockKindEnum::AssistantMessage,
            runId: 'run-1',
            seq: $state->nextSeq(),
            text: 'kept',
            streaming: false,
        ));

        $subscriber = new LlmRequestRetryProjectionSubscriber();
        $subscriber->onLlmRequestRetrying(new TranscriptProjectionEvent(
            runtimeEvent: new RuntimeEvent(
                type: RuntimeEventTypeEnum::LlmRequestRetrying->value,
                runId: 'run-1',
                seq: 0,
                payload: ['attempt' => 1, 'max_attempts' => 6, 'delay_ms' => 0, 'reason' => 'timeout'],
            ),
            state: $state,
        ));

        $this->assertNull($state->getBlock('stream-1'));
        $this->assertNotNull($state->getBlock('done-1'));
    }
}
