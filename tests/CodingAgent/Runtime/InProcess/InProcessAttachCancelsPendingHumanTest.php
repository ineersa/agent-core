<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
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
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
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

            public function stateFor(string $runId): RunState
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

            public function remember(RunState $state): void
            {
            }

            public function invalidate(string $runId): void
            {
            }

            public function clear(): void
            {
            }
        };

        $bus = new TestMessageBus();
        $container = self::getContainer();
        $client = new InProcessAgentSessionClient(
            runner: $runner,
            eventStore: $this->createStub(EventStoreInterface::class),
            mapper: $container->get(RuntimeEventMapper::class),
            historySelectionService: $this->createStub(HistorySelectionServiceInterface::class),
            systemPromptBuilder: $container->get(SystemPromptBuilder::class),
            agentsContextDiscovery: $container->get(AgentsContextDiscovery::class),
            agentsContextRenderer: $container->get(AgentsContextRenderer::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            promptTemplateService: $container->get(PromptTemplateService::class),
            sessionMetaStore: $container->get(HatfieldSessionStore::class),
            modelResolver: $container->get(ModelResolver::class),
            commandBus: $bus,
            sessionRepairService: $this->createStub(SessionRepairServiceInterface::class),
            activeRunContext: $active,
        );

        $this->assertSame($runId, $client->attach($runId)->runId);
        $this->assertSame([[$runId, 'Outstanding human questions cancelled on session attach.']], $runner->cancels);
        $this->assertCount(1, $bus->messages);
        $this->assertSame($runId, $bus->messages[0]->runId());
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
            public function stateFor(string $runId): RunState
            {
                return RunState::queued($runId)->with(['status' => RunStatus::Completed]);
            }

            public function remember(RunState $state): void
            {
            }

            public function invalidate(string $runId): void
            {
            }

            public function clear(): void
            {
            }
        };

        $bus = new TestMessageBus();
        $container = self::getContainer();
        $client = new InProcessAgentSessionClient(
            runner: $runner,
            eventStore: $this->createStub(EventStoreInterface::class),
            mapper: $container->get(RuntimeEventMapper::class),
            historySelectionService: $this->createStub(HistorySelectionServiceInterface::class),
            systemPromptBuilder: $container->get(SystemPromptBuilder::class),
            agentsContextDiscovery: $container->get(AgentsContextDiscovery::class),
            agentsContextRenderer: $container->get(AgentsContextRenderer::class),
            skillsContextBuilder: $container->get(SkillsContextBuilder::class),
            agentsContextBuilder: $container->get(AgentsContextBuilder::class),
            promptTemplateService: $container->get(PromptTemplateService::class),
            sessionMetaStore: $container->get(HatfieldSessionStore::class),
            modelResolver: $container->get(ModelResolver::class),
            commandBus: $bus,
            sessionRepairService: $this->createStub(SessionRepairServiceInterface::class),
            activeRunContext: $active,
        );

        $client->attach($runId);
        $this->assertSame(0, $runner->cancelCount);
        $this->assertCount(1, $bus->messages);
    }
}
