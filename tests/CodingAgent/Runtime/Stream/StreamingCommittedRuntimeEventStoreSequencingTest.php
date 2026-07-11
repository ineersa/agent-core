<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Stream;

use Ineersa\AgentCore\Contract\SequencedEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
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
    public function testAppendWithNextSeqEmitsPersistedAssignedSeq(): void
    {
        $inner = $this->createMock(SequencedEventStoreInterface::class);
        $input = new RunEvent('run-a', 0, 0, RunEventTypeEnum::RunStarted->value, []);
        $persisted = new RunEvent('run-a', 42, 0, RunEventTypeEnum::RunStarted->value, []);
        $inner->expects($this->once())->method('appendWithNextSeq')->with($input)->willReturn($persisted);

        $sink = new class implements RuntimeEventSinkInterface {
            /** @var list<RuntimeEvent> */
            public array $emitted = [];

            public function emit(RuntimeEvent $event): void
            {
                $this->emitted[] = $event;
            }
        };

        $mapper = new RuntimeEventMapper(new RuntimeEventTranslator(new EventDispatcher()));
        $store = new StreamingCommittedRuntimeEventStore($inner, $mapper, $sink, true);

        $returned = $store->appendWithNextSeq($input);

        $this->assertSame(42, $returned->seq);
        $this->assertCount(1, $sink->emitted);
        $this->assertSame(42, $sink->emitted[0]->seq);
    }
}
