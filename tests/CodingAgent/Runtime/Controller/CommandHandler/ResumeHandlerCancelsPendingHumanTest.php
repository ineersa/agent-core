<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\AgentRunnerInterface;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
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
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
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

            public function stateFor(string $runId): RunState
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
            commandBus: new TestMessageBus(),
            sessionRepairService: $this->createStub(SessionRepairServiceInterface::class),
            activeRunContext: $active,
        );

        $emitted = [];
        $handler = new ResumeHandler($client);
        $handler(new ControllerCommandEvent(
            new RuntimeCommand(id: 'cmd_resume_process', type: 'resume', runId: $runId),
            static function (RuntimeEvent $event) use (&$emitted): void {
                $emitted[] = $event;
            },
        ));

        $this->assertSame([[$runId, 'Outstanding human questions cancelled on session attach.']], $runner->cancels);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunResumed->value, $emitted[0]->type);
        $this->assertSame($runId, $emitted[0]->runId);
    }
}
