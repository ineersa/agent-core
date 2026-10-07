<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Coordination\FinalizeToolBatchDTO;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Per-run/per-turn/per-step tool batch preparation coordinator.
 *
 * Captures scheduling membership for LLM registration, prepares collect/human
 * deltas, and reads durable scheduling state. Persistence of registration and
 * deltas belongs to {@see ToolBatchStoreInterface} under verified transitions.
 *
 * Cross-process coordination pipeline:
 *   1. {@see LlmStepResultHandler} calls {@see prepareRegistration()} once and journals
 *      the captured {@see \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO}.
 *   2. Local metadata applies that exact membership; later collect/human deltas
 *      arm subsequent calls through the same store.
 *   3. {@see ToolCallResultHandler} calls {@see prepareCollect()} without publication.
 */
final class ToolBatchCollector
{
    public function __construct(
        private readonly ToolBatchStoreInterface $store,
        private readonly int $defaultMaxParallelism = 4,
    ) {
    }

    /**
     * Capture initial membership, queue, and in-flight admission once.
     *
     * Existing durable membership is validated and returned unchanged so
     * registration replay never resets live batch state.
     *
     * @param list<ExecuteToolCall> $toolCalls
     */
    public function prepareRegistration(string $runId, int $turnNo, string $stepId, array $toolCalls): \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO
    {
        usort(
            $toolCalls,
            static fn (ExecuteToolCall $left, ExecuteToolCall $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $expectedOrder = [];
        $callsById = [];
        $maxParallelism = $this->defaultMaxParallelism;
        foreach ($toolCalls as $toolCall) {
            $expectedOrder[$toolCall->toolCallId] = $toolCall->orderIndex;
            $callsById[$toolCall->toolCallId] = $toolCall;
            $maxParallelism = max(1, $toolCall->maxParallelism ?? $maxParallelism);
        }
        $maxParallelism = max(1, $maxParallelism);

        $existing = $this->loadBatch($runId, $turnNo, $stepId);
        if (null !== $existing) {
            if ($existing->expectedOrder !== $expectedOrder) {
                throw new \LogicException('Conflicting prepared tool batch membership.');
            }
            foreach ($callsById as $id => $call) {
                $stored = $existing->calls[$id] ?? null;
                if (null === $stored || $stored->runId() !== $call->runId() || $stored->turnNo() !== $call->turnNo()
                    || $stored->stepId() !== $call->stepId() || $stored->idempotencyKey() !== $call->idempotencyKey()
                    || $stored->toolName !== $call->toolName || $stored->args !== $call->args || (array) $stored->launchContext !== (array) $call->launchContext) {
                    throw new \LogicException('Conflicting prepared tool batch invocation.');
                }
            }

            return new \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO(
                $runId,
                $turnNo,
                $stepId,
                array_values($existing->calls),
                $existing->expectedOrder,
                array_values($existing->pendingQueue),
                $existing->inFlight,
                $existing->maxParallelism,
            );
        }

        $batch = new ToolBatchStateDTO(
            expectedOrder: $expectedOrder,
            calls: $callsById,
            pendingQueue: array_map(static fn (ExecuteToolCall $call): string => $call->toolCallId, $toolCalls),
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: $maxParallelism,
            awaitingHumanInput: [],
        );
        $this->dispatchableCalls($batch);

        return new \Ineersa\AgentCore\Domain\Coordination\RegisterToolBatchDTO(
            $runId,
            $turnNo,
            $stepId,
            array_values($callsById),
            $expectedOrder,
            array_values($batch->pendingQueue),
            $batch->inFlight,
            $maxParallelism,
        );
    }

    /** Preparation never publishes collection or changes the retained batch. */
    public function prepareCollect(ToolCallResult $result): ToolBatchCollectOutcome
    {
        $result = $result->finalized();
        $before = $this->loadBatch($result->runId(), $result->turnNo(), $result->stepId());
        if (null === $before) {
            return ToolBatchCollectOutcome::rejected();
        }
        $after = clone $before;
        $outcome = $this->applyCollectToBatch($after, $result);

        return new ToolBatchCollectOutcome(
            $outcome->accepted, $outcome->duplicate, $outcome->complete,
            $outcome->orderedResults, $outcome->effectsToDispatch,
            $this->prepareDelta($result->runId(), $result->turnNo(), $result->stepId(), $before, $after, $result),
        );
    }

    /**
     * Read a previously collected result without mutating batch state.
     *
     * Used by cancellation terminalization to project durable-but-unprojected
     * results (accepted while the batch was still incomplete) before synthesizing
     * cancelled siblings and emitting tool_batch_committed / agent_end.
     */
    public function getStoredResult(string $runId, int $turnNo, string $stepId, string $toolCallId): ?ToolCallResult
    {
        $batch = $this->loadBatch($runId, $turnNo, $stepId);
        if (null === $batch) {
            return null;
        }

        $stored = $batch->results[$toolCallId] ?? null;

        return $stored instanceof ToolCallResult ? $stored : null;
    }

    /**
     * Prepare moving an in-flight call into awaiting_human_input without a tool result.
     *
     * Duplicate same question_id is idempotent. Conflicting question_id fails.
     * May return ordinary later ExecuteToolCall effects when removing the call
     * from inFlight frees dispatch capacity under current mode/maxParallelism.
     */
    public function prepareHumanInputSuspension(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $questionId,
    ): PreparedToolBatchDTO {
        return $this->prepareWithBatch(
            $runId,
            $turnNo,
            $stepId,
            'Cannot admit tool-execution suspension for unknown batch run=%s turn=%d step=%s.',
            fn (ToolBatchStateDTO $batch): array => $this->applyHumanInputSuspensionToBatch($batch, $toolCallId, $questionId),
        );
    }

    /**
     * Inverse of {@see prepareHumanInputSuspension}: attach the typed human answer to the
     * exact stored call, clear the awaiting marker, and requeue through the existing
     * pendingQueue + dispatchableCalls/maxParallelism path.
     *
     * Idempotent when the call is already inFlight/queued with an identical answer:
     * returns the same ExecuteToolCall effect so post-commit redispatch survives CAS
     * retries and failed-once dispatch without a second lifecycle.
     * Conflicting answer or missing awaiting marker fails closed.
     */
    public function prepareHumanInputAnswer(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $questionId,
        ToolCallHumanInputAnswerDTO $answer,
    ): PreparedToolBatchDTO {
        return $this->prepareWithBatch(
            $runId,
            $turnNo,
            $stepId,
            'Cannot resume tool-execution human input for unknown batch run=%s turn=%d step=%s.',
            fn (ToolBatchStateDTO $batch): array => $this->applyHumanInputResumeToBatch($batch, $toolCallId, $questionId, $answer),
        );
    }

    /**
     * Prepare redrive after human_response committed its durable batch decision.
     *
     * Locates exactly one call for the current run/turn/step whose stored answer
     * matches `$questionId` and `$answerValue`. Returns:
     * - the exact in-flight ExecuteToolCall when already dispatched
     * - dispatchableCalls when still queued
     * - empty list when already completed (recognized no-op)
     *
     * Ambiguous matches or answer conflicts fail closed.
     */
    public function prepareHumanInputRedrive(
        string $runId,
        int $turnNo,
        string $stepId,
        string $questionId,
        mixed $answerValue,
    ): PreparedToolBatchDTO {
        return $this->prepareWithBatch(
            $runId,
            $turnNo,
            $stepId,
            'Cannot redrive tool-execution human input for unknown batch run=%s turn=%d step=%s.',
            fn (ToolBatchStateDTO $batch): array => $this->applyHumanInputRedriveToBatch($batch, $questionId, $answerValue),
        );
    }

    /** Capture cancellation settlement without synthesizing new execution work. */
    public function prepareCancelFinalization(string $runId, int $turnNo, string $stepId): ?FinalizeToolBatchDTO
    {
        $before = $this->loadBatch($runId, $turnNo, $stepId);
        if (null === $before) {
            return null;
        }
        $after = clone $before;
        $after->pendingQueue = [];
        $after->inFlight = [];
        $after->awaitingHumanInput = [];
        $after->finalized = true;

        return $this->prepareDelta($runId, $turnNo, $stepId, $before, $after);
    }

    /**
     * Compute on a private clone. Worker receipts are not part of the delta.
     *
     * @param callable(ToolBatchStateDTO): list<ExecuteToolCall> $apply
     * @param literal-string                                     $missingBatchMessage
     */
    private function prepareWithBatch(
        string $runId,
        int $turnNo,
        string $stepId,
        string $missingBatchMessage,
        callable $apply,
    ): PreparedToolBatchDTO {
        $before = $this->loadBatch($runId, $turnNo, $stepId);
        if (null === $before) {
            throw new \LogicException(\sprintf($missingBatchMessage, $runId, $turnNo, $stepId));
        }
        $after = clone $before;
        $effects = $apply($after);

        return new PreparedToolBatchDTO($effects, $this->prepareDelta($runId, $turnNo, $stepId, $before, $after));
    }

    private function prepareDelta(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $before, ToolBatchStateDTO $after, ?ToolCallResult $result = null): ?FinalizeToolBatchDTO
    {
        if ($before->pendingQueue === $after->pendingQueue
            && $before->inFlight === $after->inFlight
            && $before->awaitingHumanInput === $after->awaitingHumanInput
            && $before->finalized === $after->finalized
            && $before->results === $after->results
            && $before->calls === $after->calls) {
            return null;
        }
        $revisedId = null;
        $answer = null;
        foreach ($after->calls as $id => $call) {
            if ($call !== $before->calls[$id]) {
                if (null !== $revisedId) {
                    throw new \LogicException('Prepared human input changed multiple invocations.');
                }
                $revisedId = $id;
                $answer = $call->humanInputAnswer;
            }
        }

        return new FinalizeToolBatchDTO($runId, $turnNo, $stepId, $after->pendingQueue, $after->inFlight, $after->awaitingHumanInput, $after->finalized, $result, $revisedId, $answer);
    }

    /**
     * @return list<ExecuteToolCall>
     */
    private function applyHumanInputSuspensionToBatch(
        ToolBatchStateDTO $batch,
        string $toolCallId,
        string $questionId,
    ): array {
        if (!\array_key_exists($toolCallId, $batch->expectedOrder)) {
            throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for unexpected tool call "%s".', $toolCallId));
        }

        if (isset($batch->results[$toolCallId])) {
            throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for already completed tool call "%s".', $toolCallId));
        }

        $existingQuestionId = $batch->awaitingHumanInput[$toolCallId] ?? null;
        if (null !== $existingQuestionId) {
            if ($existingQuestionId === $questionId) {
                // Exact duplicate admission is idempotent even after capacity free.
                return [];
            }

            throw new \LogicException(\sprintf('Conflicting tool-execution suspension for call "%s": existing request "%s", new request "%s".', $toolCallId, $existingQuestionId, $questionId));
        }

        // First admission must free a currently in-flight worker slot.
        if (!isset($batch->inFlight[$toolCallId])) {
            throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for call "%s": call is not in flight.', $toolCallId));
        }

        unset($batch->inFlight[$toolCallId]);
        // A new suspension must drop any previously consumed answer metadata so a later
        // answer is distinct (e.g. a different hook suspending the same resumed call).
        $existingCall = $batch->calls[$toolCallId] ?? null;
        if ($existingCall instanceof ExecuteToolCall && null !== $existingCall->humanInputAnswer) {
            $batch->calls[$toolCallId] = $existingCall->withHumanInputAnswer(null);
        }
        $batch->awaitingHumanInput[$toolCallId] = $questionId;

        return $this->dispatchableCalls($batch);
    }

    /**
     * @return list<ExecuteToolCall>
     */
    private function applyHumanInputResumeToBatch(
        ToolBatchStateDTO $batch,
        string $toolCallId,
        string $questionId,
        ToolCallHumanInputAnswerDTO $answer,
    ): array {
        if (!\array_key_exists($toolCallId, $batch->expectedOrder)) {
            throw new \LogicException(\sprintf('Cannot resume tool-execution human input for unexpected tool call "%s".', $toolCallId));
        }

        if (isset($batch->results[$toolCallId])) {
            throw new \LogicException(\sprintf('Cannot resume tool-execution human input for already completed tool call "%s".', $toolCallId));
        }

        $existingCall = $batch->calls[$toolCallId] ?? null;
        if (!$existingCall instanceof ExecuteToolCall) {
            throw new \LogicException(\sprintf('Cannot resume tool-execution human input for missing stored call "%s".', $toolCallId));
        }

        $awaitingQuestionId = $batch->awaitingHumanInput[$toolCallId] ?? null;
        $existingAnswer = $existingCall->humanInputAnswer;

        // Idempotent CAS / post-commit retry: already requeued or in-flight with identical
        // answer → return the exact ExecuteToolCall so the undispatched effect is not lost.
        if (null === $awaitingQuestionId) {
            if ($existingAnswer instanceof ToolCallHumanInputAnswerDTO && $existingAnswer->isEquivalent($answer)) {
                if (isset($batch->inFlight[$toolCallId])) {
                    return [$existingCall];
                }
                if (\in_array($toolCallId, $batch->pendingQueue, true)) {
                    return $this->dispatchableCalls($batch);
                }
            }

            throw new \LogicException(\sprintf('Cannot resume tool-execution human input for call "%s": not awaiting human input.', $toolCallId));
        }

        if ($awaitingQuestionId !== $questionId || $answer->questionId !== $questionId) {
            throw new \LogicException(\sprintf('Cannot resume tool-execution human input for call "%s": question_id mismatch (awaiting="%s", answer="%s", expected="%s").', $toolCallId, $awaitingQuestionId, $answer->questionId, $questionId));
        }

        $batch->calls[$toolCallId] = $existingCall->withAuthorizedHumanAnswer($answer);
        unset($batch->awaitingHumanInput[$toolCallId]);

        // Requeue at the front so capacity-aware dispatch picks this exact call next.
        $batch->pendingQueue = array_values(array_filter(
            $batch->pendingQueue,
            static fn (string $id): bool => $id !== $toolCallId,
        ));
        array_unshift($batch->pendingQueue, $toolCallId);

        return $this->dispatchableCalls($batch);
    }

    /**
     * @return list<ExecuteToolCall>
     */
    private function applyHumanInputRedriveToBatch(
        ToolBatchStateDTO $batch,
        string $questionId,
        mixed $answerValue,
    ): array {
        $matches = [];
        foreach ($batch->calls as $toolCallId => $call) {
            if (!$call instanceof ExecuteToolCall) {
                continue;
            }
            $storedAnswer = $call->humanInputAnswer;
            if (!$storedAnswer instanceof ToolCallHumanInputAnswerDTO) {
                continue;
            }
            if ($storedAnswer->questionId !== $questionId) {
                continue;
            }
            $matches[$toolCallId] = $call;
        }

        if ([] === $matches) {
            throw new \LogicException(\sprintf('Cannot redrive tool-execution human input for question "%s": no stored answered call.', $questionId));
        }
        if (\count($matches) > 1) {
            throw new \LogicException(\sprintf('Cannot redrive tool-execution human input for question "%s": ambiguous answered call match.', $questionId));
        }

        $toolCallId = array_key_first($matches);
        $existingCall = $matches[$toolCallId];
        $existingAnswer = $existingCall->humanInputAnswer;
        if (!$existingAnswer instanceof ToolCallHumanInputAnswerDTO || $existingAnswer->answer !== $answerValue) {
            throw new \LogicException(\sprintf('Cannot redrive tool-execution human input for call "%s": stored answer conflicts with redrive answer.', $toolCallId));
        }

        // Recognized completed no-op: answer already applied and tool finished.
        if (isset($batch->results[$toolCallId])) {
            return [];
        }

        if (isset($batch->inFlight[$toolCallId])) {
            return [$existingCall];
        }

        if (\in_array($toolCallId, $batch->pendingQueue, true)) {
            return $this->dispatchableCalls($batch);
        }

        // Answer is durable but call is neither completed, in-flight, nor queued:
        // requeue through the normal capacity-aware path.
        $batch->pendingQueue = array_values(array_filter(
            $batch->pendingQueue,
            static fn (string $id): bool => $id !== $toolCallId,
        ));
        array_unshift($batch->pendingQueue, $toolCallId);

        return $this->dispatchableCalls($batch);
    }

    private function applyCollectToBatch(ToolBatchStateDTO $batch, ToolCallResult $result): ToolBatchCollectOutcome
    {
        if (!\array_key_exists($result->toolCallId, $batch->expectedOrder)) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (isset($batch->results[$result->toolCallId])) {
            return $this->outcomeForStoredResult($batch, $result);
        }

        if ($batch->finalized) {
            return $this->outcomeForStoredResult($batch, $result);
        }

        // Ordinary results continue collecting while a sibling call may be
        // awaiting human input; clear any awaiting marker for this call id.
        unset($batch->inFlight[$result->toolCallId], $batch->awaitingHumanInput[$result->toolCallId]);
        $batch->results[$result->toolCallId] = $result;

        $effectsToDispatch = $this->dispatchableCalls($batch);

        if (\count($batch->results) !== \count($batch->expectedOrder)) {
            return ToolBatchCollectOutcome::acceptedPending($effectsToDispatch);
        }

        $orderedResults = array_values($batch->results);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        $batch->finalized = true;

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults, $effectsToDispatch);
    }

    private function outcomeForStoredResult(ToolBatchStateDTO $batch, ToolCallResult $result): ToolBatchCollectOutcome
    {
        $stored = $batch->results[$result->toolCallId] ?? null;
        if (!$stored instanceof ToolCallResult) {
            return ToolBatchCollectOutcome::rejected();
        }

        if (!$this->toolResultsEquivalent($stored, $result)) {
            throw new \LogicException(\sprintf('Conflicting duplicate tool result for call "%s" on run "%s".', $result->toolCallId, $result->runId()));
        }

        if (!$batch->finalized) {
            return ToolBatchCollectOutcome::duplicate();
        }

        if (\count($batch->results) !== \count($batch->expectedOrder)) {
            return ToolBatchCollectOutcome::duplicate();
        }

        $orderedResults = array_values($batch->results);
        usort(
            $orderedResults,
            static fn (ToolCallResult $left, ToolCallResult $right): int => $left->orderIndex <=> $right->orderIndex,
        );

        return ToolBatchCollectOutcome::acceptedComplete($orderedResults, []);
    }

    private function toolResultsEquivalent(ToolCallResult $left, ToolCallResult $right): bool
    {
        return $left->toolCallId === $right->toolCallId
            && $left->orderIndex === $right->orderIndex
            && $left->isError === $right->isError
            && $left->result === $right->result
            && $left->error === $right->error;
    }

    /**
     * @return list<ExecuteToolCall>
     */
    private function dispatchableCalls(ToolBatchStateDTO $batch): array
    {
        $dispatch = [];

        while ([] !== $batch->pendingQueue) {
            $nextCallId = $batch->pendingQueue[0];
            if (isset($batch->results[$nextCallId]) || isset($batch->awaitingHumanInput[$nextCallId])) {
                array_shift($batch->pendingQueue);

                continue;
            }

            $nextCall = $batch->calls[$nextCallId] ?? null;

            if (!$nextCall instanceof ExecuteToolCall) {
                array_shift($batch->pendingQueue);

                continue;
            }

            $mode = ToolExecutionMode::tryFrom((string) ($nextCall->mode ?? ToolExecutionMode::Sequential->value))
                ?? ToolExecutionMode::Sequential;

            if (ToolExecutionMode::Sequential === $mode || ToolExecutionMode::Interrupt === $mode) {
                if ([] !== $batch->inFlight) {
                    break;
                }

                array_shift($batch->pendingQueue);
                $batch->inFlight[$nextCallId] = true;
                $dispatch[] = $nextCall;

                break;
            }

            if (\count($batch->inFlight) >= $batch->maxParallelism) {
                break;
            }

            array_shift($batch->pendingQueue);
            $batch->inFlight[$nextCallId] = true;
            $dispatch[] = $nextCall;
        }

        return $dispatch;
    }

    private function loadBatch(string $runId, int $turnNo, string $stepId): ?ToolBatchStateDTO
    {
        return $this->store->load($runId, $turnNo, $stepId);
    }
}
