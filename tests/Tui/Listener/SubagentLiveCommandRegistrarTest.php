<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Listener\AgentsCancelCommandHandler;
use Ineersa\Tui\Listener\SubagentLiveCommandRegistrar;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(SubagentLiveCommandRegistrar::class)]
#[CoversClass(AgentsCancelCommandHandler::class)]
final class SubagentLiveCommandRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function registersAgentsCancelWithSlashCommandHandlerContract(): void
    {
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'subagent-live-registrar');
        $state = new TuiSessionState('subagent-live-registrar');
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $registrar = $this->createRegistrar($registry);
        $registrar->register($context);

        self::assertTrue($registry->has('agents-cancel'));

        $meta = $registry->getMetadata('agents-cancel');
        self::assertInstanceOf(CommandMetadata::class, $meta);
        self::assertSame('agents-cancel', $meta->name);
        self::assertSame('/agents-cancel', $meta->usage);

        $result = $registry->execute(new SlashCommand('agents-cancel', '', '/agents-cancel'));
        self::assertInstanceOf(NoOp::class, $result);
    }

    #[Test]
    public function agentsCancelDispatchesChildCancelWhenLiveViewChildIsActive(): void
    {
        $registry = new SlashCommandRegistry();
        $harness = new VirtualTuiHarness(sessionId: 'subagent-live-cancel');
        $state = new TuiSessionState('subagent-live-cancel');
        $child = new SubagentLiveChildDTO(
            agentRunId: 'child-run-9',
            artifactId: 'agent_abc',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'task',
            lastActivityAtMs: 1,
        );
        $state->subagentLiveView->enter($child);
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;

        /** @var AgentSessionClient&MockObject $client */
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects(self::once())
            ->method('cancel')
            ->with('child-run-9');

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->withClient($client)
            ->build();

        $registrar = $this->createRegistrar($registry);
        $registrar->register($context);

        $result = $registry->execute(new SlashCommand('agents-cancel', '', '/agents-cancel'));

        self::assertInstanceOf(NoOp::class, $result);
        self::assertSame(RunActivityStateEnum::Cancelling, $state->subagentLiveView->childActivity);
    }

    #[Test]
    public function agentsCancelHandlerImplementsSlashCommandHandler(): void
    {
        $handler = new AgentsCancelCommandHandler(
            new TuiSessionState('contract-check'),
            (new VirtualTuiHarness(sessionId: 'contract-check'))->screen(),
            $this->createStub(AgentSessionClient::class),
            new SubagentLiveInputPolicy(),
            new NullLogger(),
        );

        self::assertInstanceOf(SlashCommandHandler::class, $handler);
    }

    private function createRegistrar(SlashCommandRegistry $registry): SubagentLiveCommandRegistrar
    {
        $picker = new SubagentLivePickerController(
            new SubagentLiveChildViewPoller(
                $this->createStub(TranscriptProjectorInterface::class),
                new NullLogger(),
            ),
        );

        return new SubagentLiveCommandRegistrar(
            $registry,
            $picker,
            new SubagentLiveInputPolicy(),
            new NullLogger(),
        );
    }
}
