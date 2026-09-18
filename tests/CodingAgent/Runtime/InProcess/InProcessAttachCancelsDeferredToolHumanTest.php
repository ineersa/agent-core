<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\InProcess;

use Ineersa\AgentCore\Application\Handler\CommandRouter;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\StepDispatcher;
use Ineersa\AgentCore\Application\Handler\ToolBatchCollector;
use Ineersa\AgentCore\Application\Pipeline\AgentRunner;
use Ineersa\AgentCore\Application\Pipeline\ApplyCommandHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Pipeline\RunMessageProcessor;
use Ineersa\AgentCore\Contract\History\HistorySelectionServiceInterface;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Run\CurrentToolCallDTO;
use Ineersa\AgentCore\Domain\Run\PendingHumanInputRequestDTO;
use Ineersa\AgentCore\Domain\Run\RunOperationalToolCallStatusEnum;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Run\ToolBatchIdentity;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryCommandStore;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Builder\RunStateBuilder;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestActiveRunContext;
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
use Psr\Log\NullLogger;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\InMemoryStore;
use Symfony\Component\Messenger\Handler\HandlersLocator;
use Symfony\Component\Messenger\MessageBus;
use Symfony\Component\Messenger\Middleware\HandleMessageMiddleware;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Attach cancel of deferred tool-call HITL must reach Cancelled with synthetic
 * tool results via real ApplyCommandHandler + event store, not Cancelling limbo.
 */
final class InProcessAttachCancelsDeferredToolHumanTest extends IsolatedKernelTestCase
{
    #[Test]
    public function attachCancelsDeferredToolHumanAndPersistsTerminalEvents(): void
    {
        $runId = self::getContainer()->get(HatfieldSessionStore::class)->createSession();
        $active = new TestActiveRunContext();
        $eventStore = new InMemoryEventStore();

        $collector = new ToolBatchCollector();
        $collector->registerExpectedBatch($runId, 1, 'step-attach', [
            new ExecuteToolCall($runId, 1, 'step-attach', 1, 'idemp-attach', 'call-attach', 'bash', ['command' => 'ls'], 0),
        ]);
        $collector->admitHumanInputSuspension($runId, 1, 'step-attach', 'call-attach', 'q-attach');

        $commandStore = new InMemoryCommandStore();
        $router = new CommandRouter([]);
        $applyHandler = new ApplyCommandHandler(
            commandStore: $commandStore,
            commandRouter: $router,
            commandMailboxPolicy: new CommandMailboxPolicy($commandStore, $router),
            eventFactory: new EventFactory(),
            messageNormalizer: new AgentMessageNormalizer(),
            maxPendingCommands: 10,
            toolBatchCollector: $collector,
            serializer: AttributeSerializerValidatorTestFactory::serializer(),
        );

        $commandBus = new TestMessageBus();
        $processor = new RunMessageProcessor(
            activeRunContext: $active,
            runLockManager: new RunLockManager(new LockFactory(new InMemoryStore())),
            runCommit: new RunCommit(
                activeRunContext: $active,
                eventStore: $eventStore,
                stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
                logger: new NullLogger(),
            ),
            stepDispatcher: new StepDispatcher(new TestMessageBus(), new TestMessageBus()),
            handlers: [$applyHandler],
        );

        // Route AgentRunner ApplyCommand dispatches into the processor.
        $messenger = new MessageBus([
            new HandleMessageMiddleware(new HandlersLocator([
                \Ineersa\AgentCore\Domain\Message\ApplyCommand::class => [static function (object $message) use ($processor): void {
                    $processor->process('command.apply', $message);
                }],
            ])),
        ]);

        $runner = new AgentRunner($messenger, self::getContainer()->get(SerializerInterface::class));

        $assistant = new AgentMessage(
            role: 'assistant',
            content: [['type' => 'text', 'text' => 'need approval']],
            metadata: [
                'tool_calls' => [
                    ['id' => 'call-attach', 'name' => 'bash', 'arguments' => ['command' => 'ls'], 'order_index' => 0],
                ],
            ],
        );
        $waiting = RunStateBuilder::running($runId)
            ->withStatus(RunStatus::WaitingHuman)
            ->withTurnNo(1)
            ->withLastSeq(5)
            ->withActiveStepId('step-attach')
            ->withPendingToolCalls(['call-attach' => false])
            ->withPendingHumanInputRequests([
                PendingHumanInputRequestDTO::toolCallFromPayload(
                    ['question_id' => 'q-attach', 'prompt' => 'Allow?'],
                    ['run_id' => $runId, 'turn_no' => 1, 'step_id' => 'step-attach', 'tool_call_id' => 'call-attach'],
                ),
            ])
            ->withMessages([$assistant])
            ->build()
            ->with(['currentToolCalls' => [new CurrentToolCallDTO(
                ToolBatchIdentity::fromTurnAndStep(1, 'step-attach'),
                'call-attach',
                0,
                RunOperationalToolCallStatusEnum::WaitingHuman,
                1,
            )]]);
        $active->remember($waiting);

        $container = self::getContainer();
        $client = new InProcessAgentSessionClient(
            runner: $runner,
            eventStore: $eventStore,
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
            commandBus: $commandBus,
            sessionRepairService: $this->createStub(SessionRepairServiceInterface::class),
            activeRunContext: $active,
        );

        $this->assertSame($runId, $client->attach($runId)->runId);

        $state = $active->stateFor($runId);
        $this->assertSame(RunStatus::Cancelled, $state->status);
        $this->assertSame([], $state->pendingToolCalls);
        $this->assertSame([], $state->pendingHumanInputRequests);

        $types = array_map(static fn (RunEvent $event): string => $event->type, $eventStore->allFor($runId));
        $this->assertContains(RunEventTypeEnum::AgentCommandApplied->value, $types);
        $this->assertContains(RunEventTypeEnum::ToolExecutionEnd->value, $types);
        $this->assertContains(RunEventTypeEnum::AgentEnd->value, $types);
        $this->assertNotContains(RunEventTypeEnum::WaitingHuman->value, \array_slice($types, -3));
    }
}
