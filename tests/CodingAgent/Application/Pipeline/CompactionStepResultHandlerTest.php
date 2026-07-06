<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Pipeline;

use Ineersa\AgentCore\Contract\Compaction\CompactionPrepareResult;
use Ineersa\AgentCore\Contract\Compaction\CompactionServiceInterface;
use Ineersa\AgentCore\Contract\Compaction\CompactResult;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
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
 *  - Empty summary: emits context_compaction_failed reason empty_summary, preserves messages, clears activeStepId, payload uses messages_replaced:false.
 *  - Model error: emits context_compaction_failed reason model_error, preserves messages, clears activeStepId, payload uses messages_replaced:false.
 *  - Stale result (turnNo mismatch): emits context_compaction_failed reason stale_result, preserves messages AND activeStepId (clearing would lose newer in-flight step).
 *  - Stale result (stepId mismatch): emits context_compaction_failed reason stale_result, preserves messages AND activeStepId.
 *  - Completed run with matching turnNo + activeStepId: result accepted (NOT stale_result), messages replaced, status stays Completed.
 *  - All CompactionStepResultHandler failures include step_id for replay fidelity.
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
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);

        // activeStepId cleared on terminal outcome.
        $this->assertNull($result->nextState->activeStepId);

        // Messages replaced with compacted list.
        $this->assertCount(\count($compactedMessages), $result->nextState->messages);
        $this->assertSame('This is a summary of prior context.', $result->nextState->messages[0]->content[0]['text'] ?? null);

        // payload.messages contains full replacement list.
        $payload = $result->events[0]->payload;
        $this->assertArrayHasKey('messages', $payload);
        $this->assertCount(\count($compactedMessages), $payload['messages']);
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
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        $this->assertSame('empty_summary', $result->events[0]->payload['reason']);
        $this->assertFalse($result->events[0]->payload['messages_replaced']);
        $this->assertSame('step-1', $result->events[0]->payload['step_id']);

        // activeStepId cleared on terminal outcome.
        $this->assertNull($result->nextState->activeStepId);

        // Messages preserved (not replaced).
        $this->assertCount(\count($originalMessages), $result->nextState->messages);
        $this->assertSame('hi', $result->nextState->messages[0]->content[0]['text'] ?? null);
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
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        $this->assertSame('model_error', $result->events[0]->payload['reason']);
        $this->assertSame('Connection timeout', $result->events[0]->payload['message']);
        $this->assertFalse($result->events[0]->payload['messages_replaced']);
        $this->assertSame('step-1', $result->events[0]->payload['step_id']);

        // activeStepId cleared on terminal outcome.
        $this->assertNull($result->nextState->activeStepId);

        // Messages preserved.
        $this->assertCount(\count($originalMessages), $result->nextState->messages);
        $this->assertSame('hi', $result->nextState->messages[0]->content[0]['text'] ?? null);
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
                modelOptions: [],
            ),
            $state,
        );

        // Stale → emits context_compaction_failed with stale_result reason.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        $this->assertSame('stale_result', $result->events[0]->payload['reason']);
        $this->assertFalse($result->events[0]->payload['messages_replaced']);
        $this->assertSame('step-1', $result->events[0]->payload['step_id']);

        // Messages preserved.
        $this->assertCount(\count($originalMessages), $result->nextState->messages);

        // Stale mismatch preserves current activeStepId — clearing 'step-5'
        // would lose a newer in-flight compaction's identity.
        $this->assertSame('step-5', $result->nextState->activeStepId);
    }

    public function testStaleResultEmitsFailedWhenTurnNoMismatch(): void
    {
        // Fixture models a newer in-flight compaction B (step-5) while
        // stale result A (step-1) arrives on a different turn.  The guard
        // must preserve the current active step, not clear it.
        $originalMessages = [$this->userMsg('hi')];
        $state = $this->createRunState($originalMessages, turnNo: 10, activeStepId: 'step-5');

        $fakeService = $this->createNoOpStub();
        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5, // different from state.turnNo
                stepId: 'step-1', // different from state.activeStepId 'step-5'
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
                modelOptions: [],
            ),
            $state,
        );

        // Stale (turnNo mismatch) → emits context_compaction_failed.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        $this->assertSame('stale_result', $result->events[0]->payload['reason']);
        $this->assertFalse($result->events[0]->payload['messages_replaced']);
        $this->assertSame('step-1', $result->events[0]->payload['step_id']);

        // Messages preserved.
        $this->assertCount(\count($originalMessages), $result->nextState->messages);

        // Stale turn mismatch preserves current activeStepId — a newer
        // compaction B (step-5) is in-flight and must not be cleared.
        $this->assertSame('step-5', $result->nextState->activeStepId);
    }

    public function testMatchingResultOnCompletedRunProcessesNormally(): void
    {
        // Manual /compact on a completed run: activeStepId matches stepId,
        // turnNo matches, and run status is Completed.  The matching async
        // result must be accepted — terminal run status alone is not staleness.
        $originalMessages = [
            $this->userMsg('old question'),
            $this->assistantMsg('old answer'),
        ];
        $state = new RunState(
            runId: 'run-1',
            status: RunStatus::Completed,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            messages: $originalMessages,
            activeStepId: 'step-1',
        );

        $summaryMsg = $this->userMsg('Summary of prior context.');
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
                summaryText: 'Summary of prior context.',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        // Completed run with matching correlation → accepted (NOT stale_result).
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);
        $this->assertNotEquals('stale_result', $result->events[0]->payload['reason'] ?? null);

        // Messages replaced with compacted list.
        $this->assertCount(\count($compactedMessages), $result->nextState->messages);
        $this->assertSame('Summary of prior context.', $result->nextState->messages[0]->content[0]['text'] ?? null);

        // activeStepId cleared on terminal outcome.
        $this->assertNull($result->nextState->activeStepId);

        // Run status stays Completed — compaction does not restart the run.
        $this->assertSame(RunStatus::Completed, $result->nextState->status);
    }

    /**
     * Thesis: auto-triggered compaction success includes an AdvanceRun
     * effect so the LLM turn can continue after compaction.
     *
     * Without this, the pre-LLM guard in AdvanceRunHandler would consume
     * the AdvanceRun and replace it with CompactRun — leaving the run
     * stuck after compaction with no pending continuation.
     *
     * continueAfterCompaction=true signals the pre-LLM guard path.
     */
    public function testPreLlmAutoTriggerSuccessIncludesAdvanceRunEffect(): void
    {
        $originalMessages = [
            $this->userMsg('old question'),
            $this->assistantMsg('old answer'),
        ];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('Summary.');
        $retained = [$this->userMsg('recent'), $this->assistantMsg('recent answer')];
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
                summaryText: 'Summary.',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        // ContextCompacted event emitted.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);

        // Effects must include exactly one AdvanceRun.
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(AdvanceRun::class, $result->effects[0]);

        /** @var AdvanceRun $advanceRun */
        $advanceRun = $result->effects[0];
        $this->assertSame('run-1', $advanceRun->runId());
        $this->assertSame(5, $advanceRun->turnNo());

        // Status must be Running (holding a pending LLM turn).
        $this->assertSame(RunStatus::Running, $result->nextState->status);
    }

    /**
     * Thesis: after-turn auto compaction success must NOT dispatch
     * AdvanceRun — the run was already terminal and the user must drive
     * the next turn.  The run stays Completed.
     */
    public function testAfterTurnAutoTriggerSuccessDoesNotIncludeAdvanceRunEffect(): void
    {
        $originalMessages = [
            $this->userMsg('old question'),
            $this->assistantMsg('old answer'),
        ];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('Summary.');
        $retained = [$this->userMsg('recent'), $this->assistantMsg('recent answer')];
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
                summaryText: 'Summary.',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: false,  // after-turn maintenance
                model: 'openai/gpt-4.1-mini',
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        // ContextCompacted event emitted.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);

        // Effects must NOT include an AdvanceRun — after-turn auto must not continue.
        $this->assertCount(0, $result->effects, 'After-turn auto must not auto-advance the run');

        // Status must be Completed — the run was already terminal.
        $this->assertSame(RunStatus::Completed, $result->nextState->status);
    }

    /**
     * Thesis: manual-triggered compaction (user typed /compact) must NOT
     * auto-dispatch an AdvanceRun.  The user drives the next turn.
     */
    public function testManualTriggerSuccessDoesNotIncludeAdvanceRunEffect(): void
    {
        $originalMessages = [
            $this->userMsg('old question'),
            $this->assistantMsg('old answer'),
        ];
        $state = $this->createRunState($originalMessages, turnNo: 5, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('Summary.');
        $retained = [$this->userMsg('recent'), $this->assistantMsg('recent answer')];
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
                summaryText: 'Summary.',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',  // <— manual trigger
                model: 'openai/gpt-4.1-mini',
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        // ContextCompacted event emitted.
        $this->assertNotNull($result->nextState);
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $result->events[0]->type);

        // Effects must NOT include an AdvanceRun for manual trigger.
        $this->assertCount(0, $result->effects, 'Manual /compact must not auto-advance the run');
    }

    /**
     * Thesis: pre-LLM auto-triggered compaction failure (e.g., model_error) must
     * NOT include an AdvanceRun.  Only successful compaction continues.
     */
    /**
     * Thesis: pre-LLM auto trigger model_error with continueAfterCompaction=true
     * must include an AdvanceRun effect so the pending LLM turn proceeds on
     * original (uncompacted) messages.  Without this, the run dead-ends at
     * Running with no active step and no pending work — the classic session 8
     * hang pattern.
     */
    public function testPreLlmAutoTriggerModelErrorIncludesAdvanceRunEffect(): void
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
                error: ['type' => 'HttpException', 'message' => 'timeout'],
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 1,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,  // pre-LLM guard
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status,
            'Pre-LLM auto model_error must resolve to Running');
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);

        // Effects MUST include an AdvanceRun so the pending LLM turn proceeds.
        $this->assertCount(1, $result->effects,
            'Pre-LLM auto model_error must dispatch AdvanceRun to continue pending turn');
        $this->assertInstanceOf(AdvanceRun::class, $result->effects[0]);
    }

    /**
     * Thesis: pre-LLM auto trigger success resolves Compacting → Running.
     *
     * When pre-LLM auto-compaction succeeds (continueAfterCompaction=true),
     * the run must transition to Running so the dispatched AdvanceRun can
     * continue the LLM turn normally.
     */
    public function testPreLlmAutoTriggerSuccessResolvesCompactingToRunning(): void
    {
        $originalMessages = [
            $this->userMsg('old'),
            $this->assistantMsg('answer'),
        ];
        $state = $this->createCompactingState($originalMessages, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('summary');
        $compactedMessages = [$summaryMsg, ...$originalMessages];

        $fakeService = $this->stubCompactionService($compactedMessages);
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
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $originalMessages),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status,
            'Pre-LLM auto trigger success must resolve Compacting to Running');
        $this->assertNull($result->nextState->activeStepId);
        $this->assertCount(1, $result->effects);
        $this->assertInstanceOf(AdvanceRun::class, $result->effects[0]);
    }

    /**
     * Thesis: after-turn auto trigger success resolves Compacting → Completed.
     *
     * When after-turn auto-compaction succeeds (continueAfterCompaction=false),
     * the run was already terminal — compaction is maintenance and must NOT
     * auto-continue.
     */
    public function testAfterTurnAutoTriggerSuccessResolvesCompactingToCompleted(): void
    {
        $originalMessages = [
            $this->userMsg('old'),
            $this->assistantMsg('answer'),
        ];
        $state = $this->createCompactingState($originalMessages, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('summary');
        $compactedMessages = [$summaryMsg, ...$originalMessages];

        $fakeService = $this->stubCompactionService($compactedMessages);
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
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $originalMessages),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: false,  // after-turn maintenance
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Completed, $result->nextState->status,
            'After-turn auto trigger success must resolve Compacting to Completed');
        $this->assertNull($result->nextState->activeStepId);
        $this->assertCount(0, $result->effects,
            'After-turn auto must not dispatch AdvanceRun');
    }

    /**
     * Thesis: manual trigger success resolves Compacting → Completed.
     *
     * Manual /compact is a terminal operation — the user must explicitly
     * follow up or continue.  The run should return to Completed.
     */
    public function testManualTriggerSuccessResolvesCompactingToCompleted(): void
    {
        $originalMessages = [
            $this->userMsg('old'),
            $this->assistantMsg('answer'),
        ];
        $state = $this->createCompactingState($originalMessages, activeStepId: 'step-1');

        $summaryMsg = $this->userMsg('summary');
        $compactedMessages = [$summaryMsg, ...$originalMessages];

        $fakeService = $this->stubCompactionService($compactedMessages);
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
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $originalMessages),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'manual',
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Completed, $result->nextState->status,
            'Manual trigger success must resolve Compacting to Completed');
        $this->assertNull($result->nextState->activeStepId);
        $this->assertCount(0, $result->effects,
            'Manual trigger must not dispatch AdvanceRun');
    }

    /**
     * Thesis: auto trigger failure resolves Compacting → Running.
     *
     * When auto-compaction fails (model_error/empty_summary) from a
     * Compacting state, the run should return to Running so the stalled
     * turn can attempt the LLM step without compaction.  The pre-LLM
     * guard's turn-level dedup prevents immediate re-fire.
     */
    public function testPreLlmAutoTriggerFailureResolvesCompactingToRunning(): void
    {
        $state = $this->createCompactingState([
            $this->userMsg('q'),
            $this->assistantMsg('a'),
        ], activeStepId: 'step-1');

        $handler = new CompactionStepResultHandler($this->createNoOpStub(), new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: null,
                error: ['type' => 'HttpException', 'message' => 'timeout'],
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 0,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status,
            'Pre-LLM auto trigger failure must resolve Compacting to Running');
        $this->assertNull($result->nextState->activeStepId);
        $this->assertCount(1, $result->effects,
            'Pre-LLM auto failure with continueAfterCompaction must dispatch AdvanceRun');
        $this->assertInstanceOf(AdvanceRun::class, $result->effects[0]);
    }

    /**
     * Thesis: after-turn auto trigger failure resolves Compacting → Completed.
     * When continueAfterCompaction=false, the failure is terminal — the run
     * was already done and maintenance compaction failed.
     */
    public function testAfterTurnAutoTriggerFailureResolvesCompactingToCompleted(): void
    {
        $state = $this->createCompactingState([
            $this->userMsg('q'),
            $this->assistantMsg('a'),
        ], activeStepId: 'step-1');

        $handler = new CompactionStepResultHandler($this->createNoOpStub(), new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: null,
                error: ['type' => 'HttpException', 'message' => 'timeout'],
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 0,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: false,  // after-turn maintenance
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Completed, $result->nextState->status,
            'After-turn auto trigger failure must resolve Compacting to Completed');
        $this->assertNull($result->nextState->activeStepId);
    }

    /**
     * Thesis: pre-LLM model_error during Cancelling must NOT emit AdvanceRun.
     *
     * When the user cancelled while compaction was in-flight, the incoming
     * state is Cancelling.  The handler resolves to Cancelled (terminal)
     * but must NOT also dispatch an AdvanceRun that would try to continue
     * the cancelled run.
     */
    public function testPreLlmAutoModelErrorCancellingDoesNotAdvance(): void
    {
        $state = new RunState(
            runId: 'run-1',
            status: RunStatus::Cancelling,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [$this->userMsg('q')],
            activeStepId: 'step-1',
            retryableFailure: false,
        );

        $handler = new CompactionStepResultHandler($this->createNoOpStub(), new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: null,
                error: ['type' => 'HttpException', 'message' => 'timeout'],
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 0,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status,
            'Compaction model_error during Cancelling must resolve to Cancelled');
        $this->assertCount(0, $result->effects,
            'Must NOT dispatch AdvanceRun when Cancelling — run has been cancelled');

        // Compaction outcome FIRST, agent_end LAST for replay correctness.
        // Replay applies events sequentially; when agent_end came first,
        // context_compaction_failed could overwrite Cancelled with Running.
        // Events must have unique contiguous seq numbers.
        $this->assertCount(2, $result->events, 'Must emit context_compaction_failed + agent_end');

        $failedEvent = $result->events[0];
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $failedEvent->type,
            'Must emit context_compaction_failed first');
        $this->assertSame(21, $failedEvent->seq,
            'First event seq must be lastSeq+1 (unique contiguous)');

        $agentEndEvent = $result->events[1];
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $agentEndEvent->type,
            'Must emit agent_end last (terminal event wins during replay)');
        $this->assertSame('cancelled', $agentEndEvent->payload['reason'] ?? null);
        $this->assertSame(22, $agentEndEvent->seq,
            'Second event seq must be lastSeq+2 (unique contiguous)');

        $this->assertSame(22, $result->nextState->lastSeq,
            'nextState.lastSeq must reflect both events');
    }

    /**
     * Thesis: pre-LLM empty_summary with continueAfterCompaction=true must
     * include AdvanceRun so the pending LLM turn proceeds on original messages.
     */
    public function testPreLlmAutoEmptySummaryIncludesAdvanceRunEffect(): void
    {
        $state = $this->createRunState([$this->userMsg('hi')], turnNo: 5, activeStepId: 'step-1');

        $handler = new CompactionStepResultHandler($this->createNoOpStub(), new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: '',  // empty summary
                error: null,
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 1,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Running, $result->nextState->status,
            'Pre-LLM auto empty_summary must resolve to Running');
        $this->assertCount(1, $result->events);
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $result->events[0]->type);
        $this->assertSame('empty_summary', $result->events[0]->payload['reason']);

        $this->assertCount(1, $result->effects,
            'Pre-LLM auto empty_summary must dispatch AdvanceRun to continue pending turn');
        $this->assertInstanceOf(AdvanceRun::class, $result->effects[0]);
    }

    /**
     * Thesis: pre-LLM empty_summary during Cancelling must NOT emit AdvanceRun.
     */
    public function testPreLlmAutoEmptySummaryCancellingDoesNotAdvance(): void
    {
        $state = new RunState(
            runId: 'run-1',
            status: RunStatus::Cancelling,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            activeStepId: 'step-1',
            messages: [$this->userMsg('q')],
        );

        $handler = new CompactionStepResultHandler($this->createNoOpStub(), new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-1',
                summaryText: '',  // empty summary
                error: null,
                retainedTailMessages: [],
                messagesCompacted: 0,
                messagesRetained: 0,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status,
            'Compaction empty_summary during Cancelling must resolve to Cancelled');
        $this->assertCount(0, $result->effects,
            'Must NOT dispatch AdvanceRun when Cancelling — run has been cancelled');

        // Compaction outcome FIRST, agent_end LAST — same replay contract.
        $this->assertCount(2, $result->events, 'Must emit context_compaction_failed + agent_end');

        $failedEvent = $result->events[0];
        $this->assertSame(RunEventTypeEnum::ContextCompactionFailed->value, $failedEvent->type,
            'Must emit context_compaction_failed first');
        $this->assertSame(21, $failedEvent->seq,
            'First event seq must be lastSeq+1 (unique contiguous)');

        $agentEndEvent = $result->events[1];
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $agentEndEvent->type,
            'Must emit agent_end last (terminal event wins during replay)');
        $this->assertSame('cancelled', $agentEndEvent->payload['reason'] ?? null);
        $this->assertSame(22, $agentEndEvent->seq,
            'Second event seq must be lastSeq+2 (unique contiguous)');

        $this->assertSame(22, $result->nextState->lastSeq,
            'nextState.lastSeq must reflect both events');
    }

    /**
     * Thesis: pre-LLM auto compaction success during Cancelling must emit
     * agent_end + context_compacted and must NOT dispatch AdvanceRun.
     *
     * When the user cancelled while compaction was in-flight and compaction
     * succeeded (model returned a good summary), the handler must still
     * terminalise Cancelling → Cancelled because cancellation wins over
     * continuation.  Apply compacted messages but do NOT advance the run.
     */
    public function testPreLlmAutoSuccessCancellingDoesNotAdvance(): void
    {
        $originalMessages = [
            $this->userMsg('q'),
            $this->assistantMsg('a'),
        ];
        $state = new RunState(
            runId: 'run-1',
            status: RunStatus::Cancelling,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $originalMessages,
            activeStepId: 'step-1',
            retryableFailure: false,
        );

        $summaryMsg = $this->userMsg('summary');
        $retained = [$this->userMsg('recent'), $this->assistantMsg('recent')];
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
                summaryText: 'summary text',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 1,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                continueAfterCompaction: true,
                model: 'openai/gpt-4.1-mini',
                modelOptions: [],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertSame(RunStatus::Cancelled, $result->nextState->status,
            'Compaction success during Cancelling must resolve to Cancelled');

        // Must NOT dispatch AdvanceRun — cancellation wins.
        $this->assertCount(0, $result->effects,
            'Must NOT dispatch AdvanceRun when Cancelling — run has been cancelled');

        // Compaction outcome FIRST, agent_end LAST — same replay contract.
        $this->assertCount(2, $result->events, 'Must emit context_compacted + agent_end');

        $compactedEvent = $result->events[0];
        $this->assertSame(RunEventTypeEnum::ContextCompacted->value, $compactedEvent->type,
            'Must emit context_compacted first');
        $this->assertSame(21, $compactedEvent->seq,
            'First event seq must be lastSeq+1 (unique contiguous)');

        $agentEndEvent = $result->events[1];
        $this->assertSame(RunEventTypeEnum::AgentEnd->value, $agentEndEvent->type,
            'Must emit agent_end last (terminal event wins during replay)');
        $this->assertSame('cancelled', $agentEndEvent->payload['reason'] ?? null);
        $this->assertSame(22, $agentEndEvent->seq,
            'Second event seq must be lastSeq+2 (unique contiguous)');

        $this->assertSame(22, $result->nextState->lastSeq,
            'nextState.lastSeq must reflect both events');

        // Verify compacted messages were applied.
        $this->assertSame(
            $compactedMessages,
            $result->nextState->messages,
            'Compacted messages must be applied even when cancelling',
        );
    }

    // ── ineffective compaction detection ─────────────────────────────────

    /**
     * Thesis: when the compaction service builds compacted messages but
     * the token estimate after compaction is >= the estimate before,
     * the handler must emit context_compaction_failed with reason
     * ineffective_compaction instead of context_compacted.
     *
     * Session 13 evidence: two auto compactions produced zero token
     * reduction (27316→27464 and 27528→27528) but the handler
     * unconditionally replaced messages and emitted context_compacted,
     * creating user-visible "Conversation compacted" for a useless
     * compaction.
     *
     * RED on HEAD — success path emits context_compacted unconditionally.
     */
    public function testIneffectiveCompactionEqualTokenEstimatesEmitsFailed(): void
    {
        $originalMessages = [
            $this->userMsg('old question'),
            $this->assistantMsg('old answer'),
        ];
        $state = $this->createRunState($originalMessages, 'step-1', turnNo: 5);

        $summaryMsg = $this->userMsg('summary text');
        $retained = [$this->userMsg('recent'), $this->assistantMsg('recent answer')];
        $compactedMessages = [$summaryMsg, ...$retained];

        // tokenEstimateAfter (100) >= tokenEstimateBefore (100) → ineffective.
        $fakeService = $this->stubCompactionServiceWithEstimates(
            compactedMessages: $compactedMessages,
            tokenEstimateBefore: 100,
            tokenEstimateAfter: 100,
        );

        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-ineff',
                summaryText: 'summary text',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 2,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 100,
                trigger: 'auto',
                model: 'openai/gpt-4.1-mini',
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        // Assert: emits context_compaction_failed, NOT context_compacted.
        $this->assertNotNull($result->nextState);
        $this->assertGreaterThanOrEqual(1, \count($result->events));
        $this->assertSame(
            RunEventTypeEnum::ContextCompactionFailed->value,
            $result->events[0]->type,
            'Ineffective compaction must emit context_compaction_failed, not context_compacted.',
        );
        $this->assertSame(
            'ineffective_compaction',
            $result->events[0]->payload['reason'] ?? null,
            'Failure reason must be ineffective_compaction.',
        );
        $this->assertFalse(
            $result->events[0]->payload['messages_replaced'] ?? true,
            'Messages must NOT be replaced when compaction is ineffective.',
        );
        $this->assertArrayHasKey(
            'estimated_tokens_before',
            $result->events[0]->payload,
            'Payload must include token estimate before for diagnostics.',
        );
        $this->assertArrayHasKey(
            'estimated_tokens_after',
            $result->events[0]->payload,
            'Payload must include token estimate after for diagnostics.',
        );

        // Messages must be preserved (same count as original).
        $this->assertCount(\count($originalMessages), $result->nextState->messages);
    }

    /**
     * Thesis: when tokenEstimateAfter > tokenEstimateBefore (compaction
     * INCREASED the estimate), emit context_compaction_failed.  Even a
     * single token increase is a failed compaction — summary overhead
     * exceeded the savings.
     */
    public function testIneffectiveCompactionHigherTokenEstimateEmitsFailed(): void
    {
        $originalMessages = [
            $this->userMsg('hi'),
            $this->assistantMsg('hello'),
            $this->userMsg('question'),
            $this->assistantMsg('answer'),
        ];
        $state = $this->createRunState($originalMessages, 'step-1', turnNo: 5);

        $summaryMsg = $this->userMsg('summary');
        $retained = [$this->userMsg('recent'), $this->assistantMsg('recent answer')];
        $compactedMessages = [$summaryMsg, ...$retained];

        // tokenEstimateAfter (51000) > tokenEstimateBefore (50000) → ineffective.
        $fakeService = $this->stubCompactionServiceWithEstimates(
            compactedMessages: $compactedMessages,
            tokenEstimateBefore: 50000,
            tokenEstimateAfter: 51000,
        );

        $handler = new CompactionStepResultHandler($fakeService, new EventFactory());

        $result = $handler->handle(
            new CompactionStepResult(
                runId: 'run-1',
                turnNo: 5,
                stepId: 'step-1',
                attempt: 1,
                idempotencyKey: 'key-ineff-worse',
                summaryText: 'summary',
                error: null,
                retainedTailMessages: array_map(static fn ($m) => $m->toArray(), $retained),
                messagesCompacted: 2,
                messagesRetained: 2,
                firstRetainedIndex: 0,
                tokenEstimateBefore: 50000,
                trigger: 'auto',
                model: 'openai/gpt-4.1-mini',
                modelOptions: ['thinking_level' => 'low'],
            ),
            $state,
        );

        $this->assertNotNull($result->nextState);
        $this->assertGreaterThanOrEqual(1, \count($result->events));
        $this->assertSame(
            RunEventTypeEnum::ContextCompactionFailed->value,
            $result->events[0]->type,
            'Compaction with increased estimate must emit context_compaction_failed.',
        );
        $this->assertSame(
            'ineffective_compaction',
            $result->events[0]->payload['reason'] ?? null,
        );
        $this->assertFalse(
            $result->events[0]->payload['messages_replaced'] ?? true,
        );
        // Messages preserved.
        $this->assertCount(\count($originalMessages), $result->nextState->messages);
    }

    /**
     * Create a RunState with Compacting status for testing status resolution.
     *
     * @param list<AgentMessage> $messages
     */
    private function createCompactingState(array $messages, string $activeStepId): RunState
    {
        return new RunState(
            runId: 'run-1',
            status: RunStatus::Compacting,
            version: 10,
            turnNo: 5,
            lastSeq: 20,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: $messages,
            activeStepId: $activeStepId,
            retryableFailure: false,
        );
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
            public function __construct(private array $compacted)
            {
            }

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

    /**
     * Create a stub that returns specific token estimates from buildCompactedMessages.
     *
     * @param list<AgentMessage> $compactedMessages
     */
    private function stubCompactionServiceWithEstimates(
        array $compactedMessages,
        int $tokenEstimateBefore,
        int $tokenEstimateAfter,
    ): CompactionServiceInterface {
        return new class($compactedMessages, $tokenEstimateBefore, $tokenEstimateAfter) implements CompactionServiceInterface {
            /**
             * @param list<AgentMessage> $compacted
             */
            public function __construct(
                private array $compacted,
                private int $before,
                private int $after,
            ) {
            }

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
                    tokenEstimateBefore: $this->before,
                    tokenEstimateAfter: $this->after,
                    messagesCompacted: 1,
                    messagesRetained: \count($this->compacted) - 1,
                    firstRetainedIndex: 0,
                );
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
