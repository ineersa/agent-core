<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\StartRunInput;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ResumeHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Production process resume path:
 * InteractiveMode -> JsonlProcessAgentSessionClient.attach writes JSONL resume
 * -> controller ResumeHandler -> AgentSessionClient.attach
 * (services.yaml aliases AgentSessionClient to InProcessAgentSessionClient)
 * -> cancel outstanding WaitingHuman before RefreshRunContext.
 */
#[CoversClass(ResumeHandler::class)]
#[CoversClass(InProcessAgentSessionClient::class)]
final class ResumeHandlerCancelsPendingHumanTest extends IsolatedKernelTestCase
{
    protected function tearDown(): void
    {
        self::getContainer()->get(\Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery::class)->cancel();
        parent::tearDown();
    }

    #[Test]
    public function productionResumeHandlerCancelsOutstandingHumanViaInProcessAttach(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $runner = new class implements AgentRunnerInterface {
            /** @var list<array{0: string, 1: ?string}> */
            public array $cancels = [];

            public function start(StartRunInput $input): string
            {
                return $input->runId ?? 'started';
            }

            public function shell(string $runId, string $rawInput): void
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
                $this->cancels[] = [$runId, $reason];
            }

            public function answerHuman(string $runId, string $questionId, mixed $answer): void
            {
            }

            public function compact(string $runId, ?string $customInstructions = null): void
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
                            'question_id' => 'ah_process_resume',
                            'prompt' => 'Still open after relaunch?',
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
        $canonical = [\Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($runId, 0, 'run_started', ['payload' => ['messages' => []]])];
        foreach ($fixtureState->pendingHumanInputRequests as $question) {
            $canonical[] = \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend($runId, 0, 'waiting_human', $question->waitingHumanEventPayload());
        }
        $persisted = \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::appendMany($container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class), $canonical);
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
            bootstrapSpools: self::getContainer()->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapSpoolStore::class),
            bootstrapTransfer: self::getContainer()->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapTransfer::class),
        );

        $emitted = [];
        $handler = new ResumeHandler($client, self::getContainer()->get(\Ineersa\CodingAgent\Runtime\Controller\SessionBootstrapDelivery::class));
        $handler(new ControllerCommandEvent(
            new RuntimeCommand(id: 'cmd_resume_process', type: 'resume', runId: $runId),
            static function (RuntimeEvent $event) use (&$emitted): void {
                $emitted[] = $event;
            },
        ));

        $ownerAttach = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false, new \Psr\Log\NullLogger(), $active, $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $container->get(HatfieldSessionStore::class), $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            $container->get(\Ineersa\CodingAgent\Session\Bootstrap\SessionBootstrapProducer::class),
            $container->get(\Ineersa\AgentCore\Application\Handler\RunLockManager::class),
        );
        $ownerAttach->attach($bus->messages[0]);

        $this->assertSame([], $runner->cancels);
        $this->assertSame([], $active->requireLoaded($runId)->pendingHumanInputRequests);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunResumed->value, $emitted[0]->type);
        $this->assertSame($runId, $emitted[0]->runId);
    }
}
