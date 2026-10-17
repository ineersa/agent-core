<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Replay;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use PHPUnit\Framework\TestCase;

final class RunStateReducerTest extends TestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('completionRejections')]
    public function testCompletionRejectionPreservesHealthyOrExistingRunError(string $disposition, ?string $priorError): void
    {
        $events = [new RunEvent('run', 1, 1, 'turn_advanced', ['turn_no' => 1])];
        if (null !== $priorError) {
            $events[] = new RunEvent('run', 2, 1, 'agent_command_rejected', ['kind' => 'follow_up', 'reason' => $priorError]);
        }
        $events[] = new RunEvent('run', 3, 1, 'agent_command_rejected', ['kind' => 'tool_completion', 'reason' => 'body_missing', 'delivery_disposition' => $disposition]);

        $state = $this->reducer()->replay(RunState::queued('run'), $events);

        $this->assertSame(RunStatus::Running, $state->status);
        $this->assertSame($priorError, $state->errorMessage);
    }

    public static function completionRejections(): iterable
    {
        foreach (['parked', 'rejected'] as $disposition) {
            yield $disposition.' healthy' => [$disposition, null];
            yield $disposition.' prior error' => [$disposition, 'ordinary command failed'];
        }
    }

    public function testOrdinaryCommandRejectionStillSetsAndPreservesItsError(): void
    {
        $state = $this->reducer()->replay(RunState::queued('run'), [
            new RunEvent('run', 1, 1, 'agent_command_rejected', ['kind' => 'follow_up', 'reason' => 'invalid user command']),
            new RunEvent('run', 2, 1, 'agent_command_rejected', ['kind' => 'steer']),
        ]);
        $this->assertSame('invalid user command', $state->errorMessage);
        $publicInjection = $this->reducer()->replay(RunState::queued('run'), [
            new RunEvent('run', 1, 1, 'agent_command_rejected', ['kind' => 'tool_completion', 'reason' => 'Internal runtime command cannot be submitted publicly.']),
        ]);
        $this->assertSame('Internal runtime command cannot be submitted publicly.', $publicInjection->errorMessage);
    }

    public function testStreamingReplayReleasesProcessedRecordsAndKeepsActualSequence(): void
    {
        $events = (static function (): \Generator {
            for ($seq = 1; $seq < 100; $seq += 10) {
                $event = new RunEvent('streaming', $seq, 0, 'ignored', ['body' => str_repeat('x', 1024 * 1024)]);
                $reference = \WeakReference::create($event);
                yield $event;
                unset($event);
                // Generator::current retains the yielded value until its next yield.
                // Advance with a small record before asserting the large body is released.
                yield new RunEvent('streaming', $seq + 3, 0, 'ignored', []);
                self::assertNull($reference->get());
            }
        })();

        $state = $this->reducer()->replay(RunState::queued('streaming'), $events);

        $this->assertSame(94, $state->lastSeq);
        $this->assertSame([], $state->messages);
        $this->assertFalse($events->valid());
    }

    public function testStreamingAndArrayInputsProduceTheSameState(): void
    {
        $events = [
            new RunEvent('streaming', 1, 7, 'turn_advanced', ['turn_no' => 7]),
            new RunEvent('streaming', 8, 7, 'agent_end', ['reason' => 'completed']),
        ];
        $source = (static function () use ($events): \Generator { yield from $events; })();
        $reducer = $this->reducer();

        $state = $reducer->replay(RunState::queued('streaming'), $source);

        $this->assertEquals($reducer->replay(RunState::queued('streaming'), $events), $state);
        $this->assertSame(RunStatus::Completed, $state->status);
        $this->assertSame(7, $state->turnNo);
        $this->assertSame(8, $state->lastSeq);
    }

    public function testPartialStreamFailureDoesNotReturnAState(): void
    {
        $events = (static function (): \Generator {
            yield new RunEvent('streaming', 1, 0, 'ignored', []);
            throw new \RuntimeException('Canonical record read failed.');
        })();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Canonical record read failed.');
        $this->reducer()->replay(RunState::queued('streaming'), $events);
    }

    private function reducer(): RunStateReducer
    {
        return new RunStateReducer(
            AttributeSerializerValidatorTestFactory::denormalizer(),
            new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer()),
        );
    }
}
