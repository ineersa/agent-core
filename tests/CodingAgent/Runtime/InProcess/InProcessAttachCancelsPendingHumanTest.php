<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Agent\Context\AgentsContextBuilder;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\Runtime\InProcess\InProcessAgentSessionClient;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventMapper;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Skills\SkillsContextBuilder;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextRenderer;
use Ineersa\CodingAgent\SystemPrompt\SystemPromptBuilder;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Test;

final class InProcessAttachCancelsPendingHumanTest extends IsolatedKernelTestCase
{
    #[Test]
    public function attachCancelsOutstandingWaitingHumanBeforeRefresh(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $runner = new class implements AgentRunnerInterface {
            /** @var list<array{0: string, 1: ?string}> */
            public array $cancels = [];

            public function start(\Ineersa\AgentCore\Domain\Run\StartRunInput $input): string
            {
                return $input->runId ?? 'started';
            }

            public function steer(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
            {
            }

            public function followUp(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
            {
            }

            public function appendMessage(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
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
        $active->loadRecovered($fixtureState);
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
        );

        $this->assertSame($runId, $client->attach($runId)->runId);
        $ownerAttach = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false, new \Psr\Log\NullLogger(), $active, $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $container->get(HatfieldSessionStore::class), $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class), $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            $container->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService::class),
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

            public function start(\Ineersa\AgentCore\Domain\Run\StartRunInput $input): string
            {
                return $input->runId ?? 'started';
            }

            public function steer(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
            {
            }

            public function followUp(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
            {
            }

            public function appendMessage(string $runId, \Ineersa\AgentCore\Domain\Message\AgentMessage $message): void
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
        $active->loadRecovered($fixtureState);
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
        );

        $client->attach($runId);
        $ownerAttach = new \Ineersa\CodingAgent\Application\Pipeline\SessionMaintenanceHandler(
            $container->get(\Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface::class),
            $container->get(\Ineersa\CodingAgent\Runtime\InProcess\InMemoryRuntimeEventSink::class),
            $container->get(\Ineersa\CodingAgent\Runtime\Stream\StdoutRuntimeEventSink::class),
            false, new \Psr\Log\NullLogger(), $active, $container->get(\Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor::class),
            $container->get(HatfieldSessionStore::class), $container->get(\Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware::class),
            $container->get(\Ineersa\AgentCore\Application\Pipeline\RunCommit::class), $container->get(\Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery::class),
            $container->get(\Ineersa\CodingAgent\Entity\DeferredSubagentBatchRepository::class),
            $container->get(\Ineersa\CodingAgent\Agent\Execution\Subagent\Batch\Deferred\Recovery\DeferredSubagentBatchRecoveryService::class),
        );
        $ownerAttach->attach($bus->messages[0]);
        $this->assertSame(0, $runner->cancelCount);
        $this->assertCount(1, $bus->messages);
    }
}
