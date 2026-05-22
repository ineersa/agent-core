<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Pipeline;

use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunState;
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
        private ?LoggerInterface $logger = null,
    ) {
        $this->handlers = [...$handlers];
    }

    public function process(string $scope, AbstractAgentBusMessage $message): void
    {
        $runId = $message->runId();
        $idempotencyKey = $message->idempotencyKey();

        $this->runLockManager->synchronized($runId, function () use ($scope, $message, $runId, $idempotencyKey): void {
            if ($this->idempotency->wasHandled($scope, $runId, $idempotencyKey)) {
                return;
            }

            $handler = $this->resolveHandler($message);
            $delayMs = self::INITIAL_RETRY_DELAY_MS;

            for ($attempt = 0; $attempt < self::MAX_CAS_RETRIES; ++$attempt) {
                $state = $this->runStore->get($runId) ?? RunState::queued($runId);
                $result = $handler->handle($message, $state);

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
                $this->logger?->debug('agent_loop.processor.cas_conflict_retry', [
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

            // All retries exhausted — log and drop the message.
            $this->logger?->warning('agent_loop.processor.cas_conflict_exhausted', [
                'scope' => $scope,
                'run_id' => $runId,
                'message_type' => $message::class,
                'attempts' => self::MAX_CAS_RETRIES,
            ]);
        });
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
