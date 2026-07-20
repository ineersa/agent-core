<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Handler;

use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreMutation;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;

/**
 * Per-run/per-turn/per-step tool batch execution coordinator.
 *
 * Registers expected tool calls from an LLM step, dispatches the initial batch,
 * and collects results as they arrive. With a durable {@see ToolBatchStoreInterface},
 * batch state survives consumer restarts and coordinates across Messenger workers.
 *
 * Cross-process coordination pipeline (LLM register/persist → parallel tool workers
 * → {@see ToolCallResult} on run_control → collector/store mutation):
 *   1. {@see LlmStepResultHandler} calls {@see registerExpectedBatch()}, which persists
 *      the batch and returns initial dispatchable {@see ExecuteToolCall} messages.
 *   2. Tool workers execute calls and dispatch {@see ToolCallResult} envelopes.
 *   3. {@see ToolCallResultHandler} calls {@see collect()}, which atomically mutates
 *      durable {@see ToolBatchStateDTO} state and may dispatch subsequent calls.
 *
 * In-process {@see ToolBatchStateDTO} shape is owned by that DTO; the optional
 * {@see ToolBatchStoreInterface} mirrors it for durability.
 */
final class ToolBatchCollector
{
    /** @var array<string, ToolBatchStateDTO> */
    private array $batches = [];

    private ?ToolBatchStoreInterface $store = null;

    public function __construct(
        private readonly int $defaultMaxParallelism = 4,
        ?ToolBatchStoreInterface $store = null,
    ) {
        $this->store = $store;
    }

    /**
     * @param list<ExecuteToolCall> $toolCalls
     *
     * @return list<ExecuteToolCall>
     */
    public function registerExpectedBatch(string $runId, int $turnNo, string $stepId, array $toolCalls): array
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

        $batch = new ToolBatchStateDTO(
            expectedOrder: $expectedOrder,
            calls: $callsById,
            pendingQueue: array_map(static fn (ExecuteToolCall $call): string => $call->toolCallId, $toolCalls),
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: max(1, $maxParallelism),
            awaitingHumanInput: [],
        );

        $initialDispatch = $this->dispatchableCalls($batch);
        $this->saveBatch($runId, $turnNo, $stepId, $batch);

        return $initialDispatch;
    }

    public function collect(ToolCallResult $result): ToolBatchCollectOutcome
    {
        if (null !== $this->store) {
            return $this->collectWithDurableStore($result);
        }

        return $this->collectInMemory($result);
    }

    /**
     * Move an in-flight call into awaiting_human_input without creating a tool result.
     *
     * Duplicate same question_id is idempotent. Conflicting question_id fails.
     * May return ordinary later ExecuteToolCall effects when removing the call
     * from inFlight frees dispatch capacity under current mode/maxParallelism.
     *
     * @return list<ExecuteToolCall>
     */
    public function admitHumanInputSuspension(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $questionId,
    ): array {
        if (null !== $this->store) {
            /* @var list<ExecuteToolCall> */
            return $this->store->mutate(
                $runId,
                $turnNo,
                $stepId,
                function (?ToolBatchStateDTO $stored) use ($runId, $turnNo, $stepId, $toolCallId, $questionId): ToolBatchStoreMutation {
                    if (null === $stored) {
                        throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for unknown batch run=%s turn=%d step=%s.', $runId, $turnNo, $stepId));
                    }

                    return new ToolBatchStoreMutation($this->applyHumanInputSuspensionToBatch($stored, $toolCallId, $questionId), $stored);
                },
            );
        }

        $batch = $this->loadBatch($runId, $turnNo, $stepId);
        if (null === $batch) {
            throw new \LogicException(\sprintf('Cannot admit tool-execution suspension for unknown batch run=%s turn=%d step=%s.', $runId, $turnNo, $stepId));
        }

        $effects = $this->applyHumanInputSuspensionToBatch($batch, $toolCallId, $questionId);
        $this->saveBatch($runId, $turnNo, $stepId, $batch);

        return $effects;
    }

    /**
     * Inverse of {@see admitHumanInputSuspension}: attach the typed human answer to the
     * exact stored call, clear the awaiting marker, and requeue through the existing
     * pendingQueue + dispatchableCalls/maxParallelism path.
     *
     * Idempotent when the call is already inFlight/queued with an identical answer:
     * returns the same ExecuteToolCall effect so post-commit redispatch survives CAS
     * retries and failed-once dispatch without a second lifecycle.
     * Conflicting answer or missing awaiting marker fails closed.
     *
     * @return list<ExecuteToolCall>
     */
    public function resumeHumanInputAnswer(
        string $runId,
        int $turnNo,
        string $stepId,
        string $toolCallId,
        string $questionId,
        ToolCallHumanInputAnswerDTO $answer,
    ): array {
        if (null !== $this->store) {
            /* @var list<ExecuteToolCall> */
            return $this->store->mutate(
                $runId,
                $turnNo,
                $stepId,
                function (?ToolBatchStateDTO $stored) use ($runId, $turnNo, $stepId, $toolCallId, $questionId, $answer): ToolBatchStoreMutation {
                    if (null === $stored) {
                        throw new \LogicException(\sprintf('Cannot resume tool-execution human input for unknown batch run=%s turn=%d step=%s.', $runId, $turnNo, $stepId));
                    }

                    return new ToolBatchStoreMutation($this->applyHumanInputResumeToBatch($stored, $toolCallId, $questionId, $answer), $stored);
                },
            );
        }

        $batch = $this->loadBatch($runId, $turnNo, $stepId);
        if (null === $batch) {
            throw new \LogicException(\sprintf('Cannot resume tool-execution human input for unknown batch run=%s turn=%d step=%s.', $runId, $turnNo, $stepId));
        }

        $effects = $this->applyHumanInputResumeToBatch($batch, $toolCallId, $questionId, $answer);
        $this->saveBatch($runId, $turnNo, $stepId, $batch);

        return $effects;
    }

    /**
     * Post-commit redrive after human_response already mutated durable batch state.
     *
     * Locates exactly one call for the current run/turn/step whose stored answer
     * matches `$questionId` and `$answerValue`. Returns:
     * - the exact in-flight ExecuteToolCall when already dispatched
     * - dispatchableCalls when still queued
     * - empty list when already completed (recognized no-op)
     *
     * Ambiguous matches or answer conflicts fail closed.
     *
     * @return list<ExecuteToolCall>
     */
    public function redriveHumanInputAnswer(
        string $runId,
        int $turnNo,
        string $stepId,
        string $questionId,
        mixed $answerValue,
    ): array {
        if (null !== $this->store) {
            /* @var list<ExecuteToolCall> */
            return $this->store->mutate(
                $runId,
                $turnNo,
                $stepId,
                function (?ToolBatchStateDTO $stored) use ($runId, $turnNo, $stepId, $questionId, $answerValue): ToolBatchStoreMutation {
                    if (null === $stored) {
                        throw new \LogicException(\sprintf('Cannot redrive tool-execution human input for unknown batch run=%s turn=%d step=%s.', $runId, $turnNo, $stepId));
                    }

                    return new ToolBatchStoreMutation($this->applyHumanInputRedriveToBatch($stored, $questionId, $answerValue), $stored);
                },
            );
        }

        $batch = $this->loadBatch($runId, $turnNo, $stepId);
        if (null === $batch) {
            throw new \LogicException(\sprintf('Cannot redrive tool-execution human input for unknown batch run=%s turn=%d step=%s.', $runId, $turnNo, $stepId));
        }

        $effects = $this->applyHumanInputRedriveToBatch($batch, $questionId, $answerValue);
        $this->saveBatch($runId, $turnNo, $stepId, $batch);

        return $effects;
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

        $batch->calls[$toolCallId] = $existingCall->withHumanInputAnswer($answer);
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

    private function collectWithDurableStore(ToolCallResult $result): ToolBatchCollectOutcome
    {
        $runId = $result->runId();
        $turnNo = $result->turnNo();
        $stepId = $result->stepId();

        /** @var ToolBatchCollectOutcome $outcome */
        $outcome = $this->store->mutate(
            $runId,
            $turnNo,
            $stepId,
            function (?ToolBatchStateDTO $stored) use ($result): ToolBatchStoreMutation {
                if (null === $stored) {
                    return new ToolBatchStoreMutation(ToolBatchCollectOutcome::rejected());
                }

                $collectOutcome = $this->applyCollectToBatch($stored, $result);

                if (!$collectOutcome->accepted || $collectOutcome->duplicate) {
                    return new ToolBatchStoreMutation($collectOutcome);
                }

                return new ToolBatchStoreMutation($collectOutcome, $stored);
            },
        );

        return $outcome;
    }

    private function collectInMemory(ToolCallResult $result): ToolBatchCollectOutcome
    {
        $batch = $this->loadBatch($result->runId(), $result->turnNo(), $result->stepId());

        if (null === $batch) {
            return ToolBatchCollectOutcome::rejected();
        }

        $outcome = $this->applyCollectToBatch($batch, $result);

        if ($outcome->accepted && !$outcome->duplicate) {
            $this->saveBatch($result->runId(), $result->turnNo(), $result->stepId(), $batch);
        }

        return $outcome;
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
        $batchKey = $this->batchKey($runId, $turnNo, $stepId);

        if (isset($this->batches[$batchKey])) {
            return $this->batches[$batchKey];
        }

        $stored = $this->store?->load($runId, $turnNo, $stepId);
        if (null !== $stored) {
            $this->batches[$batchKey] = $stored;

            return $stored;
        }

        return null;
    }

    private function saveBatch(string $runId, int $turnNo, string $stepId, ToolBatchStateDTO $batch): void
    {
        if (null !== $this->store) {
            // Store-first: durable write must succeed before any in-process view changes
            // so Messenger retry reloads the last persisted snapshot, not a dirty cache.
            $this->store->save($runId, $turnNo, $stepId, $batch);

            return;
        }

        $this->batches[$this->batchKey($runId, $turnNo, $stepId)] = $batch;
    }

    private function batchKey(string $runId, int $turnNo, string $stepId): string
    {
        return \sprintf('%s|%d|%s', $runId, $turnNo, $stepId);
    }
}
