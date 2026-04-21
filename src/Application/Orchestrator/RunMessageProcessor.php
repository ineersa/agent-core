<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Application\Orchestrator;

use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Message\AbstractAgentBusMessage;
use Ineersa\AgentCore\Domain\Run\RunState;

/**
 * The RunMessageProcessor executes the shared lock/idempotency/load/commit pipeline around dedicated run message handlers.
 */
final readonly class RunMessageProcessor
{
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

            $state = $this->runStore->get($runId) ?? RunState::queued($runId);

            $handler = $this->resolveHandler($message);
            $result = $handler->handle($message, $state);

            if (null !== $result->nextState) {
                $committed = $this->runCommit->commit($state, $result->nextState, $result->events, $result->effects);
                if (!$committed) {
                    return;
                }

                if ([] !== $result->postCommitEffects) {
                    $this->stepDispatcher->dispatchEffects($result->postCommitEffects);
                }

                foreach ($result->postCommit as $callback) {
                    $callback();
                }
            }

            if ($result->markHandled) {
                $this->idempotency->markHandled($scope, $runId, $idempotencyKey);
            }
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
