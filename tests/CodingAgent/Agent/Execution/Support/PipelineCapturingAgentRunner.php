<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Support;

use Ineersa\AgentCore\Application\Handler\MessageIdempotencyService;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Application\Pipeline\RunOrchestrator;
use Ineersa\AgentCore\Application\Pipeline\StartRunHandler;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\RunStoreInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Domain\Message\StartRunPayload;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Tests\Application\Handler\InMemoryIdempotencyStore;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestSerializerFactory;
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Uid\Uuid;

/**
 * AgentRunner that commits StartRun through the real RunOrchestrator pipeline.
 *
 * @internal
 */
final class PipelineCapturingAgentRunner implements AgentRunnerInterface
{
    public ?StartRunInput $lastStartInput = null;

    public function __construct(
        private readonly RunOrchestrator $orchestrator,
        private readonly RunStoreInterface $runStore,
        private readonly EventStoreInterface $eventStore,
    ) {
    }

    public static function create(RunStoreInterface $runStore, EventStoreInterface $eventStore): self
    {
        $commandBus = new TestMessageBus();
        $executionBus = new TestMessageBus();
        $commandStore = new \Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore();
        $runCommit = new RunCommit(
            runStore: $runStore,
            eventStore: $eventStore,
            commandStore: $commandStore,
            hotPromptStateRebuilder: new \Ineersa\CodingAgent\Session\Replay\SessionHotPromptReplayService(
                $eventStore,
                new \Ineersa\AgentCore\Infrastructure\Storage\HotPromptStateStore(),
                new \Ineersa\AgentCore\Application\Replay\PromptStateReplayService(),
                new \Ineersa\AgentCore\Application\Replay\ReplayEventPreparer(),
            ),
            stepDispatcher: new StepDispatcher($executionBus),
            logger: new NullLogger(),
            hookDispatcher: null,
        );
        $processor = new RunMessageProcessor(
            runStore: $runStore,
            idempotency: new MessageIdempotencyService(new InMemoryIdempotencyStore()),
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: $runCommit,
            stepDispatcher: new StepDispatcher($executionBus),
            logger: new NullLogger(),
            handlers: [
                new StartRunHandler(
                    eventFactory: new EventFactory(),
                    normalizer: TestSerializerFactory::normalizer(),
                ),
            ],
        );

        return new self(new RunOrchestrator($processor), $runStore, $eventStore);
    }

    public function start(StartRunInput $input): string
    {
        $this->lastStartInput = $input;
        $runId = $input->runId ?? Uuid::v4()->toRfc4122();
        $stepId = 'start-'.hrtime(true);

        $this->orchestrator->onStartRun(new StartRun(
            runId: $runId,
            turnNo: 0,
            stepId: $stepId,
            attempt: 1,
            idempotencyKey: hash('sha256', $runId.'|'.$stepId),
            payload: new StartRunPayload(
                systemPrompt: $input->systemPrompt,
                messages: $input->messages,
                metadata: $input->metadata,
            ),
        ));

        return $runId;
    }

    public function continue(string $runId): void
    {
    }

    public function steer(string $runId, AgentMessage $message): void
    {
    }

    public function followUp(string $runId, AgentMessage $message): void
    {
    }

    public function appendMessage(string $runId, AgentMessage $message): void
    {
    }

    public function cancel(string $runId, ?string $reason = null): void
    {
    }

    public function answerHuman(string $runId, string $questionId, mixed $answer): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}
