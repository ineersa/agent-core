<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session\History;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\RunPresentationReader;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

final class RunPresentationReaderTest extends IsolatedKernelTestCase
{
    public function testRealArchiveCompactionDiscardAndSuppressedSeeds(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('presentation');
        $events = self::getContainer()->get(EventStoreInterface::class);
        $append = static function (int $turn, string $type, array $payload = []) use ($run, $events): void {
            $events->append(RunEvent::forAppend($run, $turn, $type, $payload));
        };
        $append(0, 'run_started', ['payload' => ['messages' => [$this->message('system', 'SYSTEM_SECRET'), $this->message('user', 'initial')]]]);
        $append(2, 'turn_advanced', ['turn_no' => 2]);
        $append(2, 'llm_step_completed', ['assistant_message' => $this->message('assistant', 'old')]);
        $append(7, 'turn_advanced', ['turn_no' => 7]);
        $append(7, 'llm_step_completed', ['assistant_message' => $this->message('assistant', 'DISCARDED_SECRET')]);
        $append(2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'history_select']);
        $append(2, 'history_tail_discarded', ['after_turn_no' => 2]);
        $append(2, 'context_compacted', ['messages' => [$this->message('system', 'COMPACTION_SYSTEM_SECRET'), $this->message('user', 'compacted prompt'), $this->message('assistant', 'checkpoint')]]);
        $append(2, 'context_refreshed', ['messages' => [$this->message('system', 'NEW_SYSTEM_SECRET'), $this->message('user-context', 'GENERATED_SECRET', ['source' => 'agents_context'])]]);
        $assistant = $this->message('assistant', str_repeat('Visible answer ', 100));
        $assistant['tool_calls'] = [['id' => 'call_current', 'function' => ['name' => 'read', 'arguments' => 'ARGUMENT_SECRET']]];
        $assistant['details'] = ['thinking' => 'REASONING_SECRET'];
        $append(2, 'llm_step_completed', ['assistant_message' => $assistant]);
        $append(2, 'agent_end', ['reason' => 'completed']);
        $append(2, 'agent_command_queued', ['kind' => 'follow_up']);
        $append(2, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message('user', 'SUPPRESSED_SECRET')]);
        $append(2, 'history_position_set', ['position_turn_no' => 2, 'reason' => 'history_select']);
        $cut = $events->latestSequenceFor($run);
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState(runId: $run, status: RunStatus::Completed, turnNo: 2, lastSeq: $cut));
        $product = self::getContainer()->get(RunPresentationReader::class)->read($run, 1, 240);
        $this->assertNotNull($product);
        $this->assertSame(5, $product->messageCount);
        $this->assertSame(3, $product->eligibleMessageCount);
        $this->assertSame(1, $product->pendingToolCallCount);
        $this->assertSame('call_current', $product->firstPendingToolCallId);
        $this->assertSame(2, $product->turnNo);
        $this->assertSame($cut, $product->lastSeq);
        $this->assertCount(1, $product->historyLines);
        $this->assertLessThanOrEqual(800, mb_strlen($product->assistantExcerpt));
        $this->assertStringContainsString('Visible answer', $product->historyLines[0]);
        $this->assertStringNotContainsString('SECRET', implode('', $product->historyLines).$product->assistantExcerpt);
    }

    public function testHistoricalStatusDoesNotDependOnDisposableOperationalRows(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('stale presentation');
        $events = self::getContainer()->get(EventStoreInterface::class);
        $events->append(RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => []]]));
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState(runId: $run, status: RunStatus::Completed, lastSeq: 0));
        $product = self::getContainer()->get(RunPresentationReader::class)->read($run, 1, 240);
        $this->assertSame(RunStatus::Running, $product->status);
        self::getContainer()->get(RunOperationalProjectionRepository::class)->deleteForOwnerSession($run);
        $product = self::getContainer()->get(RunPresentationReader::class)->read($run, 1, 240);
        $this->assertSame(RunStatus::Running, $product->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('terminalEvents')]
    public function testTerminalPresentationPreservesExecutionReplayAccumulators(string $type, array $payload, RunStatus $status): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('terminal presentation');
        $events = self::getContainer()->get(EventStoreInterface::class);
        $events->append(RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => []]]));
        $assistant = $this->message('assistant', 'Partial work');
        $assistant['tool_calls'] = [['id' => 'pending_call', 'function' => ['name' => 'read', 'arguments' => '{}']]];
        $events->append(RunEvent::forAppend($run, 0, 'llm_step_completed', ['assistant_message' => $assistant]));
        $codec = self::getContainer()->get(ToolExecutionEndPayloadCodec::class);
        $events->append(RunEvent::forAppend($run, 0, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult($run, 0, 'old-step', 1, 'old-result', 'pending_call', 0, ['tool_name' => 'read', 'output' => 'old uncommitted result']))));
        $reader = self::getContainer()->get(RunPresentationReader::class);
        $before = $reader->read($run, 0, 800);
        $this->assertNotNull($before);
        $this->assertSame(1, $before->pendingToolCallCount);
        $events->append(RunEvent::forAppend($run, 0, $type, $payload));
        $execution = self::getContainer()->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertNotNull($execution);
        $this->assertSame(['pending_call' => true], $execution->pendingToolCalls);
        $this->assertCount(1, $execution->messages);
        $product = $reader->read($run, 0, 800);
        $this->assertNotNull($product);
        $this->assertSame($status, $product->status);
        $this->assertSame(1, $product->pendingToolCallCount);
        $this->assertSame('pending_call', $product->firstPendingToolCallId);
        $this->assertSame(\count($execution->pendingToolCalls), $product->pendingToolCallCount);
        $this->assertSame(\count($execution->messages), $product->messageCount);
        $this->assertSame('Partial work', $product->assistantExcerpt);

        // Execution replay retains the completed-result accumulator across a
        // terminal event. Presentation must preserve this inherited count quirk.
        $events->append(RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'new-step']));
        $events->append(RunEvent::forAppend($run, 1, 'agent_command_applied', ['kind' => 'follow_up', 'message' => $this->message('user', 'Continue')]));
        $next = $this->message('assistant', 'New work');
        $next['tool_calls'] = [['id' => 'new_call', 'function' => ['name' => 'read', 'arguments' => '{}']]];
        $events->append(RunEvent::forAppend($run, 1, 'llm_step_completed', ['assistant_message' => $next]));
        $events->append(RunEvent::forAppend($run, 1, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult($run, 1, 'new-step', 1, 'new-result', 'new_call', 0, ['tool_name' => 'read', 'output' => 'new result']))));
        $events->append(RunEvent::forAppend($run, 1, 'tool_batch_committed'));
        $resumedExecution = self::getContainer()->get(\Ineersa\AgentCore\Contract\Replay\RunStateRebuilderInterface::class)->rebuildIfStale(RunState::queued($run), $run)->rebuiltState;
        $this->assertNotNull($resumedExecution);
        $this->assertCount(5, $resumedExecution->messages);
        $this->assertSame([], $resumedExecution->pendingToolCalls);
        $resumed = $reader->read($run, 0, 800);
        $this->assertNotNull($resumed);
        $this->assertSame(5, $resumed->messageCount);
        $this->assertSame(0, $resumed->pendingToolCallCount);
        $this->assertSame(\count($resumedExecution->messages), $resumed->messageCount);
        $this->assertSame(\count($resumedExecution->pendingToolCalls), $resumed->pendingToolCallCount);
    }

    /** @return iterable<string, array{string, array<string, mixed>, RunStatus}> */
    public static function terminalEvents(): iterable
    {
        yield 'failed end' => ['agent_end', ['reason' => 'failed'], RunStatus::Failed];
        yield 'cancelled end' => ['agent_end', ['reason' => 'cancelled'], RunStatus::Cancelled];
        yield 'llm failure' => ['llm_step_failed', ['error' => ['message' => 'failed']], RunStatus::Failed];
    }

    public function testDecodedLargePayloadsAreReleasedInBothArchivePasses(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('bounded presentation');
        // Physical archive fixtures do not need live runtime event publication.
        $events = self::getContainer()->get(SessionRunEventStore::class);
        for ($i = 0; $i < 12; ++$i) {
            $events->append(RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => [$this->message('user', str_repeat('x', 1024 * 1024))]]]));
        }
        $cut = $events->latestSequenceFor($run);
        self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState(runId: $run, status: RunStatus::Completed, lastSeq: $cut));
        $observed = $this->createMock(EventStoreInterface::class);
        $observed->method('latestSequenceFor')->willReturn($cut);
        $observed->expects($this->exactly(2))->method('rangeFor')->with($run, 1, $cut)->willReturnCallback(static function (string $id, int $from, int $to) use ($events): iterable {
            $refs = [];
            foreach ($events->rangeFor($id, $from, $to) as $event) {
                if (2 === \count($refs)) {
                    self::assertNull(array_shift($refs)->get());
                }
                $refs[] = \WeakReference::create($event);
                yield $event;
            }
        });
        $reader = new RunPresentationReader($observed, self::getContainer()->get(RunLockManager::class), new ReplayAssistantMessageFactory(), self::getContainer()->get(ToolExecutionEndPayloadCodec::class));
        $product = $reader->read($run, 2, 240);
        $this->assertSame(12, $product->messageCount);
        $this->assertSame(12, $product->eligibleMessageCount);
        $this->assertCount(2, $product->historyLines);
        foreach ($product->historyLines as $line) {
            $this->assertLessThanOrEqual(260, mb_strlen($line));
        }
    }

    public function testCommittedToolMessagesCountWithoutRetainingResultOrReasoningBodies(): void
    {
        $run = self::getContainer()->get(HatfieldSessionStore::class)->createSession('tool presentation');
        $events = self::getContainer()->get(SessionRunEventStore::class);
        $events->append(RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['messages' => [$this->message('system', 'secret'), $this->message('user', 'prompt')]]]));
        $events->append(RunEvent::forAppend($run, 0, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => 'call']]]]));
        $codec = self::getContainer()->get(ToolExecutionEndPayloadCodec::class);
        $events->append(RunEvent::forAppend($run, 0, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult(runId: $run, turnNo: 0, stepId: 'step', attempt: 1, idempotencyKey: 'result', toolCallId: 'call', orderIndex: 0, result: ['tool_name' => 'read', 'output' => 'TOOL_SECRET']))));
        $events->append(RunEvent::forAppend($run, 0, 'tool_batch_committed'));
        $events->append(RunEvent::forAppend($run, 0, 'llm_step_completed', ['assistant_message' => ['role' => 'assistant', 'content' => null, 'details' => ['thinking' => 'REASONING_SECRET']]]));
        $events->append(RunEvent::forAppend($run, 0, 'agent_end', ['reason' => 'completed']));
        $product = self::getContainer()->get(RunPresentationReader::class)->read($run, 2, 240);
        $this->assertSame(4, $product->messageCount);
        $this->assertSame(2, $product->eligibleMessageCount);
        $this->assertSame(0, $product->pendingToolCallCount);
        $this->assertSame('Completed with status completed.', $product->assistantExcerpt);
        $this->assertFalse($product->includeAssistantExcerpt);
        $this->assertTrue($product->hasAssistantMessage);
        $this->assertStringNotContainsString('SECRET', implode('', $product->historyLines));
    }

    /** @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function message(string $role, string $text, array $metadata = []): array
    {
        return ['role' => $role, 'content' => [['type' => 'text', 'text' => $text]], 'metadata' => $metadata];
    }
}
