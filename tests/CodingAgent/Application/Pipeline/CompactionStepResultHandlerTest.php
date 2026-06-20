<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\HandlerResult;
use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\CompactionStepResult;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for {@see CompactionStepResultHandler}.
 *
 * Theses:
 *  - Success: emits context_compacted, replaces messages with [summary, ...tail], clears activeStepId.
 *  - Empty summary: emits context_compaction_failed reason empty_summary, preserves messages, clears activeStepId.
 *  - Model error: emits context_compaction_failed reason model_error, preserves messages, clears activeStepId.
 *  - Stale result (turnNo mismatch): emits context_compaction_failed reason stale_result, preserves messages.
 *  - Stale result (stepId mismatch): emits context_compaction_failed reason stale_result, preserves messages.
 *  - Terminal run emits context_compaction_failed reason stale_result.
 */
final class CompactionStepResultHandlerTest extends TestCase
{
    public function testSuccessEmitsCompactedAndReplacesMessages(): void
    {
        $originalMessages = [
            $this->userMsg('old question'),
            $this->assistantMsg('old answer'),
        ];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('This is a summary of prior context.');
        $retained = [$this->userMsg('recent question'), $this->assistantMsg('recent answer')];
        $compactedMessages = [$summaryMsg, ...$retained];

        $fakeService = $this->stubCompactionService($compactedMessages);

        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: 'This is a summary of prior context.',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                thinkingLevel: 'low',
            ),
            $state,
        );

        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);

        // activeStepId cleared on terminal outcome.
        self::assertNull($result->nextState->activeStepId);

        // Messages replaced with compacted list.
        self::assertCount(\count($compactedMessages), $result->nextState->messages);
        self::assertSame('This is a summary of prior context.', $result->nextState->messages[0]->content[0]['text'] ?? null);

        // payload.messages contains full replacement list.
        $payload = $result->events[0]->payload;
        self::assertArrayHasKey('messages', $payload);
        self::assertCount(\count($compactedMessages), $payload['messages']);
    }

    public function testEmptySummaryEmitsFailedWithEmptySummaryReason(): void
    {
        $originalMessages = [$this->userMsg('hi'), $this->assistantMsg('hello')];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-1');

        $fakeService = $this->createNoOpStub();
        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: '   ',  // whitespace-only
                error: null,
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                thinkingLevel: null,
            ),
            $state,
        );

        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        self::assertSame('empty_summary', $result->events[0]->payload['reason']);
        self::assertTrue($result->events[0]->payload['preserved_messages']);

        // activeStepId cleared on terminal outcome.
        self::assertNull($result->nextState->activeStepId);

        // Messages preserved (not replaced).
        self::assertCount(\count($originalMessages), $result->nextState->messages);
        self::assertSame('hi', $result->nextState->messages[0]->content[0]['text'] ?? null);
    }

    public function testModelErrorEmitsFailedWithModelErrorReason(): void
    {
        $originalMessages = [$this->userMsg('hi')];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-1');

        $fakeService = $this->createNoOpStub();
        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: null,
                error: ['type' => 'HttpException', 'message' => 'Connection timeout'],
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 1,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                thinkingLevel: null,
            ),
            $state,
        );

        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        self::assertSame('model_error', $result->events[0]->payload['reason']);
        self::assertSame('Connection timeout', $result->events[0]->payload['message']);
        self::assertTrue($result->events[0]->payload['preserved_messages']);

        // activeStepId cleared on terminal outcome.
        self::assertNull($result->nextState->activeStepId);

        // Messages preserved.
        self::assertCount(\count($originalMessages), $result->nextState->messages);
        self::assertSame('hi', $result->nextState->messages[0]->content[0]['text'] ?? null);
    }

    public function testStaleResultEmitsFailedWhenStepIdMismatch(): void
    {
        $originalMessages = [$this->userMsg('hi'), $this->assistantMsg('hello')];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-5');

        $fakeService = $this->createNoOpStub();
        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1', // different from state.activeStepId
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: 'summary text',
                error: null,
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                thinkingLevel: null,
            ),
            $state,
        );

        // Stale → emits context_compaction_failed with stale_result reason.
        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        self::assertSame('stale_result', $result->events[0]->payload['reason']);
        self::assertTrue($result->events[0]->payload['preserved_messages']);

        // Messages preserved.
        self::assertCount(\count($originalMessages), $result->nextState->messages);
    }

    public function testStaleResultEmitsFailedWhenTurnNoMismatch(): void
    {
        $originalMessages = [$this->userMsg('hi')];
        $state = $this->createRunState($originalMessages, turnNo: 10, activeStepId: 'step-1');

        $fakeService = $this->createNoOpStub();
        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5, // different from state.turnNo
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: 'summary text',
                error: null,
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 1,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                thinkingLevel: null,
            ),
            $state,
        );

        // Stale (turnNo mismatch) → emits context_compaction_failed.
        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        self::assertSame('stale_result', $result->events[0]->payload['reason']);
        self::assertTrue($result->events[0]->payload['preserved_messages']);

        // Messages preserved.
        self::assertCount(\count($originalMessages), $result->nextState->messages);
    }

    public function testResultInTerminalRunEmitsFailed(): void
    {
        $messages = [$this->userMsg('hi')];
        $state = new RunState(
            runId: 'run-1',
            status: RunStatus::Completed,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            messages: $messages,
            activeStepId: 'step-1',
        );

        $fakeService = $this->createNoOpStub();
        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: 'summary text',
                error: null,
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 0,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                thinkingLevel: null,
            ),
            $state,
        );

        // Terminal run → emits context_compaction_failed stale_result.
        self::assertNotNull($result->nextState);
        self::assertCount(1, $result->events);
        self::assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        self::assertSame('stale_result', $result->events[0]->payload['reason']);
        self::assertTrue($result->events[0]->payload['preserved_messages']);
    }

    // ── helpers ──

    /**
     * @param list<AgentMessage> $messages
     */
    private function createRunState(array $messages, string $activeStepId, int $turnNo = 5): RunState
    {
        return new RunState(
            runId: 'run-1',
            status: RunStatus::Running,
            version: 10,
            turnNo: $turnNo,
            lastSeq: 20,
            messages: $messages,
            activeStepId: $activeStepId,
        );
    }

    /**
     * @param list<AgentMessage> $compactedMessages
     */
    private function stubCompactionService(array $compactedMessages): CompactionServiceInterface
    {
        return new class($compactedMessages) implements CompactionServiceInterface {
            /** @param list<AgentMessage> $compacted */
            public function __construct(private array $compacted) {}

            public function prepare(array $messages): CompactionPrepareResult
            {
                throw new \LogicException('Not expected in this test.');
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                throw new \LogicException('Not expected in this test.');
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                return new CompactResult(
                    summaryText: $summaryText,
                    summaryMessage: $this->compacted[0] ?? new AgentMessage('assistant', $summaryText),
                    compactedMessages: $this->compacted,
                    tokenEstimateBefore: 50000,
                    tokenEstimateAfter: 10000,
                    messagesCompacted: 1,
                    messagesRetained: \count($this->compacted) - 1,
                    firstRetainedIndex: 0,
                );
            }
        };
    }

    private function createNoOpStub(): CompactionServiceInterface
    {
        return new class implements CompactionServiceInterface {
            public function prepare(array $messages): CompactionPrepareResult
            {
                throw new \LogicException('Not expected in this test path.');
            }

            public function buildSummarizationMessages(CompactionPrepareResult $result, ?string $customInstructions): array
            {
                throw new \LogicException('Not expected in this test path.');
            }

            public function buildCompactedMessages(string $summaryText, CompactionPrepareResult $result): CompactResult
            {
                throw new \LogicException('Not expected in this test path.');
            }
        };
    }

    private function userMsg(string $text): AgentMessage
    {
        return new AgentMessage('user', [['type' => 'text', 'text' => $text]]);
    }

    private function assistantMsg(string $text): AgentMessage
    {
        return new AgentMessage('assistant', [['type' => 'text', 'text' => $text]]);
    }
}
