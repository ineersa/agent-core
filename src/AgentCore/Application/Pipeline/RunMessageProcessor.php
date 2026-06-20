<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\RunStateReplayException;
use Ineersa\AgentCore\Application\Handler\RunStateReplayService;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\RunLogContext;
use Psr\Log\LoggerInterface;

/**
 * Orchestrates message processing through the run pipeline.
 *
 * For each message:
 *  1. Acquires the run lock (re-entrant-safe)
 *  2. Checks idempotency — skips if already handled
 *  3. Re-reads the latest run state from the store
 *  4. Resolves and runs the appropriate handler
 *  5. Commits state changes with CAS retry and exponential backoff
 *  6. Dispatches post-commit effects and callbacks
 *  7. Marks the message as handled
 *
 * The CAS retry loop (ASYNC-06) re-reads state and re-executes the
 * handler on each attempt, ensuring correctness under concurrent
 * consumer contention.
 */
final readonly class RunMessageProcessor
{
    /** Maximum consecutive CAS retry attempts before giving up. */
    private const int MAX_CAS_RETRIES = 3;

    /** Initial retry delay in milliseconds (doubles each attempt). */
    private const int INITIAL_RETRY_DELAY_MS = 50;

    /** @var list<RunMessageHandler> */
    private array $handlers;

    /**
     * @param iterable<RunMessageHandler> $handlers
     */
    public function __construct(
        private RunStoreInterface $runStore,
        private MessageIdempotencyService $idempotency,
        private RunLockManager $runLockManager,
        private RunCommit $runCommit,
        private StepDispatcher $stepDispatcher,
        iterable $handlers,
        private LoggerInterface $logger,
        private ?RunStateReplayService $runStateReplayService = null,
    ) {
        $this->handlers = [...$handlers];
    }

    public function process(string $scope, AbstractAgentBusMessage $message): void
    {
        $runId = $message->runId();
        $idempotencyKey = $message->idempotencyKey();

        RunLogContext::enter([
            'run_id' => $runId,
            'scope' => $scope,
            'queue' => 'agent.command.bus',
            'message_type' => $message::class,
        ]);

        try {
            $this->runLockManager->synchronized($runId, function () use ($scope, $message, $runId, $idempotencyKey): void {
                if ($this->idempotency->wasHandled($scope, $runId, $idempotencyKey)) {
                    return;
                }

                $handler = $this->resolveHandler($message);

                // Set handler context for inner logs.
                RunLogContext::enter([
                    'handler' => $handler::class,
                    'component' => self::componentForHandler($handler::class),
                ]);

                try {
                    $this->processWithRetry($scope, $message, $runId, $idempotencyKey, $handler);
                } finally {
                    RunLogContext::leave(); // handler context
                }
            });
        } finally {
            RunLogContext::leave(); // run context
        }
    }

    private function processWithRetry(string $scope, AbstractAgentBusMessage $message, string $runId, string $idempotencyKey, RunMessageHandler $handler): void
    {
        $delayMs = self::INITIAL_RETRY_DELAY_MS;

        for ($attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt) {
            $state = $this->runStore->get($runId) ?? RunState::queued($runId);

            // Rebuild stale or missing state from canonical events so the
            // handler operates on a correct baseline.  This ensures that
            // state.json is a rebuildable hot checkpoint, not a required
            // source of truth.
            if (null !== $this->runStateReplayService) {
                try {
                    $replayResult = $this->runStateReplayService->rebuildIfStale($state, $runId);

                    if ($replayResult->rebuilt && null !== $replayResult->rebuiltState) {
                        $state = $replayResult->rebuiltState;

                        $this->logger->info('messenger.state.rebuilt_from_events', [
                            'run_id' => $runId,
                            'state_last_seq' => $replayResult->maxEventSeq,
                            'event_count' => $replayResult->eventCount,
                            'component' => 'runtime',
                        ]);
                    }
                } catch (RunStateReplayException $replayException) {
                    // Non-contiguous or corrupted history — fail explicitly
                    // rather than continuing from a stale or queued state.
                    $this->logger->error('messenger.state.replay_failed', [
                        'run_id' => $runId,
                        'reason' => $replayException->getMessage(),
                        'component' => 'runtime',
                    ]);

                    throw $replayException;
                }
            }

            RunLogContext::enter(['retry_count' => $attempt]);
            try {
                $result = $handler->handle($message, $state);
            } finally {
                RunLogContext::leave(); // retry_count
            }

            // No state change — nothing to commit.
            if (null === $result->nextState) {
                if ($result->markHandled) {
                    $this->idempotency->markHandled($scope, $runId, $idempotencyKey);
                }

                return;
            }

            $committed = $this->runCommit->commit(
                $state,
                $result->nextState,
                $result->events,
                $result->effects,
            );

            if ($committed) {
                // Success — post-commit effects and callbacks.
                if ([] !== $result->postCommitEffects) {
                    $this->stepDispatcher->dispatchEffects($result->postCommitEffects);
                }

                foreach ($result->postCommit as $callback) {
                    $callback();
                }

                if ($result->markHandled) {
                    $this->idempotency->markHandled($scope, $runId, $idempotencyKey);
                }

                return;
            }

            // CAS conflict — retry with exponential backoff.
            $this->logger->warning('messenger.message.cas_conflict_retry', [
                'scope' => $scope,
                'run_id' => $runId,
                'message_type' => $message::class,
                'attempt' => $attempt + 1,
                'max_retries' => self::MAX_CAS_RETRIES,
            ]);

            if ($attempt < self::MAX_CAS_RETRIES - 1) {
                usleep($delayMs * 1000);
                $delayMs *= 2;
            }
        }

        // All retries exhausted — throw so the message is properly
        // rejected and the transport can handle it (e.g., retry or DLQ).
        $this->logger->error('messenger.message.cas_conflict_exhausted', [
            'scope' => $scope,
            'run_id' => $runId,
            'message_type' => $message::class,
            'attempts' => self::MAX_CAS_RETRIES,
        ]);

        throw new CasRetryExhaustedException(\sprintf('CAS conflict exhausted after %d attempts for run %s, message %s', self::MAX_CAS_RETRIES, $runId, $message::class));
    }

    private static function componentForHandler(string $handlerClass): string
    {
        return match ($handlerClass) {
            'Ineersa\AgentCore\Application\Pipeline\StartRunHandler' => 'runtime',
            'Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler' => 'runtime',
            'Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler' => 'runtime',
            'Ineersa\AgentCore\Application\Pipeline\LlmStepResultHandler' => 'llm',
            'Ineersa\AgentCore\Application\Pipeline\ToolCallResultHandler' => 'tool',
            'Ineersa\CodingAgent\Application\Pipeline\CompactRunHandler' => 'compaction',
            'Ineersa\CodingAgent\Application\Pipeline\CompactionStepResultHandler' => 'compaction',
            default => 'runtime',
        };
    }

    private function resolveHandler(object $message): RunMessageHandler
    {
        foreach ($this->handlers as $handler) {
            if ($handler->supports($message)) {
                return $handler;
            }
        }

        throw new \LogicException(\sprintf('No run message handler supports message of type "%s".', $message::class));
    }
}
