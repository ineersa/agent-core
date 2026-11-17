<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Session\Goal\SessionGoalService;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Listener\GoalCommandRegistrar;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;

final class GoalCommandHandlerTest extends IsolatedKernelTestCase
{
    use TuiRuntimeContextBuilderTrait;

    public function testDraftGoalUsesNormalPromotionAndPersistsBeforeInitialDispatch(): void
    {
        $state = new TuiSessionState('');
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('start')->willReturnCallback(function (StartRunRequest $request) use ($state): RunHandle {
            $this->assertNotSame('', $state->sessionId);
            $this->assertSame($state->sessionId, $request->runId);
            $this->assertSame('Ship the feature', self::getContainer()->get(SessionGoalService::class)->get($state->sessionId));
            $this->assertStringContainsString('Ship the feature', $request->prompt);

            return new RunHandle($request->runId);
        });
        $client->expects($this->never())->method('send');
        $harness = $this->mount($state, $client);
        try {
            $harness->sendInput('/goal Ship the feature');
            $harness->sendInput("\r");
            $this->assertNotNull($state->handle);
            $this->assertNull($state->pendingGoal);
            $this->assertSame('Ship the feature', self::getContainer()->get(SessionGoalService::class)->get($state->sessionId));
        } finally {
            $harness->stopInputLoop();
        }
    }

    public function testStatusEditorAndCancelWhileBusyUseExistingLocalCommandPath(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Existing objective');
        $state = new TuiSessionState($id);
        $state->handle = new RunHandle($id);
        $state->activity = RunActivityStateEnum::Running;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('start');
        $client->expects($this->never())->method('send');
        $client->expects($this->never())->method('cancel');
        $harness = $this->mount($state, $client);
        try {
            $harness->sendInput('/goal');
            $harness->sendInput("\r");
            $this->assertStringContainsString('Existing objective', $harness->plainScreenText());
            $harness->sendInput('/goal edit');
            $harness->sendInput("\r");
            $this->assertSame('/goal Existing objective', $harness->screen()->editorText());
            $harness->screen()->promptEditor()->setText('/goal CANCEL');
            $harness->sendInput("\r");
            $this->assertNull(self::getContainer()->get(SessionGoalService::class)->get($id));
            $this->assertSame(RunActivityStateEnum::Running, $state->activity);
            $this->assertStringContainsString('Session goal cleared', $harness->plainScreenText());
        } finally {
            $harness->stopInputLoop();
        }
    }

    public function testControlPrefixInsideObjectiveRemainsTextAndInvalidEditDoesNotOverwrite(): void
    {
        $id = self::getContainer()->get(HatfieldSessionStore::class)->createSession('', 'Existing');
        $state = new TuiSessionState($id);
        $state->handle = new RunHandle($id);
        $state->activity = RunActivityStateEnum::Completed;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send')->willReturnCallback(function (string $runId, UserCommand $command) use ($id): void {
            $this->assertSame($id, $runId);
            $this->assertSame('cancel the migration and explain why', self::getContainer()->get(SessionGoalService::class)->get($id));
            $this->assertStringContainsString('cancel the migration', $command->text);
        });
        $harness = $this->mount($state, $client);
        try {
            $harness->sendInput('/goal cancel the migration and explain why');
            $harness->sendInput("\r");
            $harness->screen()->promptEditor()->setText('/goal '.str_repeat('x', 4001));
            $harness->sendInput("\r");
            $this->assertSame('cancel the migration and explain why', self::getContainer()->get(SessionGoalService::class)->get($id));
        } finally {
            $harness->stopInputLoop();
        }
    }

    private function mount(TuiSessionState $state, AgentSessionClient $client): VirtualTuiHarness
    {
        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);
        $catalog = new SlashCommandCatalog();
        $registrar = self::getContainer()->get(GoalCommandRegistrar::class);
        $registrar->registerCatalog($catalog);
        $services = $this->createSessionServices(tui: $harness->tui(), screen: $harness->screen(), state: $state, client: $client, catalog: $catalog);
        $context = $this->buildTuiContext()->withTui($harness->tui())->withScreen($harness->screen())->withState($state)->withClient($client)->withSessionStore(self::getContainer()->get(HatfieldSessionStore::class))->withSessionServices($services)->build();
        $registrar->register($context);
        self::getContainer()->get(SubmitListener::class)->register($context);
        $harness->startInputLoop();

        return $harness;
    }
}
