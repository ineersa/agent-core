<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\VerifiedTransitionDTO;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeEventSinkInterface;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Stream\StreamingCommittedRuntimeEventStore;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[AllowMockObjectsWithoutExpectations]
final class StreamingCommittedRuntimeEventStoreSequencingTest extends TestCase
{
    public function testVerifiedFinalizationEmitsPersistedAssignedSeqWithoutArchiveReread(): void
    {
        $inner = $this->createMock(PreparedTransitionEventStoreInterface::class);
        $input = new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []);
        $persisted = new RunEvent('run-a', 42, 0, RunEventTypeEnum::RunStarted->value, []);
        $pending = new VerifiedTransitionDTO('transition-a', ['run_id' => 'run-a'], [42]);

        $inner->expects($this->once())->method('appendTransition')->with([$input], ['run_id' => 'run-a'])->willReturn([$persisted]);
        $inner->expects($this->atLeastOnce())->method('verifiedPendingTransition')->with('run-a')->willReturn($pending);
        $inner->expects($this->once())->method('finalizeVerifiedTransition')->with('run-a', 'transition-a');
        $inner->expects($this->never())->method('rangeFor');

        $sink = new class implements RuntimeEventSinkInterface {
            /** @var list<RuntimeEvent> */
            public array $emitted = [];

            public function emit(RuntimeEvent $event): void
            {
                $this->emitted[] = $event;
            }
        };

        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(
            new EventDispatcher(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
        ));
        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);

        $returned = $store->appendTransition([$input], ['run_id' => 'run-a'])[0];
        $this->assertCount(0, $sink->emitted);
        $store->finalizeVerifiedTransition('run-a', 'transition-a');

        $this->assertSame(42, $returned->seq);
        $this->assertCount(1, $sink->emitted);
        $this->assertSame(42, $sink->emitted[0]->seq);
    }
}
