<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\ChildInvocationBackgroundService;
use Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\BackgroundWorkSnapshotProvider;
use Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore;
use Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcessManager;
use Ineersa\CodingAgent\Tool\BashBackgroundRequestService;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;

final class InProcessAttachCancelsPendingHumanTest extends IsolatedKernelTestCase
{
    #[Test]
    public function attachCancelsOutstandingWaitingHumanBeforeRefresh(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $runner = new class implements AgentRunnerInterface {
            /** @var list<array{0: string, 1: ?string}> */
            public array $cancels = [];

            public function start(StartRunInput $input): string
            {
                return $input->runId ?? 'started';
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
                $this->cancels[] = [$runId, $reason];
            }

            public function answerHuman(string $runId, string $questionId, mixed $answer): void
            {
            }

            public function compact(string $runId, ?string $customInstructions = null): void
            {
            }

            public function shell(string $runId, string $rawInput): void
            {
            }
        };

        $active = new class($runId) implements ActiveRunContextInterface {
            public function __construct(private string $runId)
            {
            }

            public function createNew(string $runId): RunState
            {
                throw new \LogicException('Fixture does not support initialization');
            }

            public function loadRecovered(RunState $state): void
            {
                throw new \LogicException('Fixture does not support recovery');
            }

            public function requireLoaded(string $runId): RunState
            {
                return new RunState(
                    runId: $runId,
                    status: RunStatus::WaitingHuman,
                    pendingHumanInputRequests: [
                        PendingHumanInputRequestDTO::modelTurnFromInterruptPayload([
                            'question_id' => 'ah_attach',
                            'prompt' => 'Still open?',
                        ]),
                    ],
                );
            }

            public function replaceCurrent(RunState $state): void
            {
            }

            public function release(string $runId): void
            {
            }
        };

        $bus = new TestMessageBus();
        $container = self::getContainer();
        $fixtureState = $active->requireLoaded($runId);
        $active = $container->get(ActiveRunContextInterface::class);
        $canonical = [RunEvent::forAppend($runId, 0, 'run_started', ['payload' => ['messages' => []]])];
        foreach ($fixtureState->pendingHumanInputRequests as $question) {
            $canonical[] = RunEvent::forAppend($runId, 0, 'waiting_human', $question->waitingHumanEventPayload());
        }
        $persisted = PreparedEventStoreSeeder::appendMany($container->get(PreparedTransitionEventStoreInterface::class), $canonical);
        $active->loadRecovered($fixtureState->with(['lastSeq' => $persisted[\count($persisted) - 1]->seq]));
        $client = new InProcessAgentSessionClient(
            runner: $runner,
            eventStore: $this->createStub(EventStoreInterface::class),
            mapper: $container->get(RuntimeEventMapper::class),
            systemPromptBuilder: $container->get(SystemPromptBuilder::class),
            agentsContextDiscovery: $container->get(AgentsContextDiscovery::class),
            agentsContextRenderer: $container->get(AgentsContextRenderer::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            promptTemplateService: $container->get(PromptTemplateService::class),
            sessionMetaStore: $container->get(HatfieldSessionStore::class),
            modelResolver: $container->get(ModelResolver::class),
            commandBus: $bus,
            backgroundRequests: self::getContainer()->get(BashBackgroundRequestService::class),
            backgroundProcesses: self::getContainer()->get(BackgroundProcessManager::class),
            childBackground: self::getContainer()->get(ChildInvocationBackgroundService::class),
            backgroundWork: self::getContainer()->get(BackgroundWorkSnapshotProvider::class),
            bootstrapSpools: self::getContainer()->get(SessionBootstrapSpoolStore::class),
            bootstrapTransfer: self::getContainer()->get(SessionBootstrapTransfer::class),
        );

        $this->assertSame($runId, $client->attach($runId)->runId);
        $ownerAttach = new SessionMaintenanceHandler(
            $container->get(HistorySelectionServiceInterface::class),
            $container->get(SessionRepairServiceInterface::class),
            $container->get(InMemoryRuntimeEventSink::class),
            $container->get(StdoutRuntimeEventSink::class),
            false, new NullLogger(), $active, $container->get(RunMessageProcessor::class),
            $container->get(OwnerRunInitializationMiddleware::class),
            $container->get(PendingTransitionRecovery::class),
            $container->get(DeferredSubagentBatchRepository::class),
            $container->get(SessionBootstrapProducer::class),
            $container->get(RunLockManager::class),
        );
        $ownerAttach->attach($bus->messages[0]);

        $this->assertSame([], $runner->cancels);
        $this->assertSame([], $active->requireLoaded($runId)->pendingHumanInputRequests);
        $this->assertCount(1, $bus->messages);
        $this->assertSame($runId, $bus->messages[0]->runId);
    }

    #[Test]
    public function attachSkipsCancelWhenNoPendingHuman(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $runner = new class implements AgentRunnerInterface {
            public int $cancelCount = 0;

            public function start(StartRunInput $input): string
            {
                return $input->runId ?? 'started';
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
                ++$this->cancelCount;
            }

            public function answerHuman(string $runId, string $questionId, mixed $answer): void
            {
            }

            public function compact(string $runId, ?string $customInstructions = null): void
            {
            }

            public function shell(string $runId, string $rawInput): void
            {
            }
        };

        $active = new class implements ActiveRunContextInterface {
            public function createNew(string $runId): RunState
            {
                throw new \LogicException('Fixture does not support initialization');
            }

            public function loadRecovered(RunState $state): void
            {
                throw new \LogicException('Fixture does not support recovery');
            }

            public function requireLoaded(string $runId): RunState
            {
                return RunState::queued($runId)->with(['status' => RunStatus::Completed]);
            }

            public function replaceCurrent(RunState $state): void
            {
            }

            public function release(string $runId): void
            {
            }
        };

        $bus = new TestMessageBus();
        $container = self::getContainer();
        $fixtureState = $active->requireLoaded($runId);
        $active = $container->get(ActiveRunContextInterface::class);
        $canonical = [RunEvent::forAppend($runId, 0, 'run_started', ['payload' => ['messages' => []]])];
        foreach ($fixtureState->pendingHumanInputRequests as $question) {
            $canonical[] = RunEvent::forAppend($runId, 0, 'waiting_human', $question->waitingHumanEventPayload());
        }
        $persisted = PreparedEventStoreSeeder::appendMany($container->get(PreparedTransitionEventStoreInterface::class), $canonical);
        $active->loadRecovered($fixtureState->with(['lastSeq' => $persisted[\count($persisted) - 1]->seq]));
        $client = new InProcessAgentSessionClient(
            runner: $runner,
            eventStore: $this->createStub(EventStoreInterface::class),
            mapper: $container->get(RuntimeEventMapper::class),
            systemPromptBuilder: $container->get(SystemPromptBuilder::class),
            agentsContextDiscovery: $container->get(AgentsContextDiscovery::class),
            agentsContextRenderer: $container->get(AgentsContextRenderer::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            promptTemplateService: $container->get(PromptTemplateService::class),
            sessionMetaStore: $container->get(HatfieldSessionStore::class),
            modelResolver: $container->get(ModelResolver::class),
            commandBus: $bus,
            backgroundRequests: self::getContainer()->get(BashBackgroundRequestService::class),
            backgroundProcesses: self::getContainer()->get(BackgroundProcessManager::class),
            childBackground: self::getContainer()->get(ChildInvocationBackgroundService::class),
            backgroundWork: self::getContainer()->get(BackgroundWorkSnapshotProvider::class),
            bootstrapSpools: self::getContainer()->get(SessionBootstrapSpoolStore::class),
            bootstrapTransfer: self::getContainer()->get(SessionBootstrapTransfer::class),
        );

        $client->attach($runId);
        $ownerAttach = new SessionMaintenanceHandler(
            $container->get(HistorySelectionServiceInterface::class),
            $container->get(SessionRepairServiceInterface::class),
            $container->get(InMemoryRuntimeEventSink::class),
            $container->get(StdoutRuntimeEventSink::class),
            false, new NullLogger(), $active, $container->get(RunMessageProcessor::class),
            $container->get(OwnerRunInitializationMiddleware::class),
            $container->get(PendingTransitionRecovery::class),
            $container->get(DeferredSubagentBatchRepository::class),
            $container->get(SessionBootstrapProducer::class),
            $container->get(RunLockManager::class),
        );
        $ownerAttach->attach($bus->messages[0]);
        $this->assertSame(0, $runner->cancelCount);
        $this->assertCount(1, $bus->messages);
    }
}
