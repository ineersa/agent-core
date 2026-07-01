<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Application\TuiSessionSwitchService;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Tui\Tui;

#[CoversClass(TuiSessionSwitchService::class)]
final class SessionSwitchServiceTest extends TestCase
{
    private function createCoordinator(): QuestionCoordinator
    {
        return new QuestionCoordinator();
    }

    private function createController(QuestionCoordinator $coordinator): QuestionController
    {
        return new QuestionController($coordinator);
    }

    private function createService(
        ?QuestionCoordinator $coordinator = null,
        ?QuestionController $controller = null,
        ?TranscriptProjectorInterface $projector = null,
        ?LoggerInterface $logger = null,
    ): TuiSessionSwitchService {
        return new TuiSessionSwitchService(
            $coordinator ?? $this->createCoordinator(),
            $controller ?? $this->createController($coordinator ?? $this->createCoordinator()),
            $projector ?? $this->createStub(TranscriptProjectorInterface::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    public function testHasPendingSwitchIsFalseInitially(): void
    {
        $service = $this->createService();

        self::assertFalse($service->hasPendingSwitch());
    }

    public function testConsumePendingSwitchReturnsNullWhenNothingPending(): void
    {
        $service = $this->createService();

        self::assertNull($service->consumePendingSwitch());
    }

    public function testRequestResumeSetsPendingResumeTarget(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), new TuiSessionState('old', false));

        $service->requestResume('42');

        self::assertTrue($service->hasPendingSwitch());

        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertFalse($target->isDraft);
        self::assertSame('42', $target->sessionId);
        self::assertNull($target->request);

        // After consume, nothing pending
        self::assertFalse($service->hasPendingSwitch());
        self::assertNull($service->consumePendingSwitch());
    }

    public function testRequestNewDraftSetsPendingDraftTarget(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), new TuiSessionState('old', false));

        $service->requestNewDraft();

        self::assertTrue($service->hasPendingSwitch());

        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertTrue($target->isDraft);
        self::assertNull($target->sessionId);
        self::assertNull($target->request);

        self::assertFalse($service->hasPendingSwitch());
    }

    public function testRequestNewDraftWithRequestPassesThrough(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), new TuiSessionState('old', false));

        $req = new StartRunRequest(prompt: 'from /new', runId: '');
        $service->requestNewDraft($req);

        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertTrue($target->isDraft);
        self::assertSame($req, $target->request);
    }

    public function testSwitchResetsQuestionCoordinatorState(): void
    {
        $coordinator = $this->createCoordinator();
        $coordinator->enqueue(new QuestionRequest(
            requestId: 'q1',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'Test?',
        ));
        $coordinator->enqueue(new QuestionRequest(
            requestId: 'q2',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'Test 2?',
        ));

        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), new TuiSessionState('old', false));

        $service->requestNewDraft();

        // After switch request, coordinator should be reset
        self::assertNull($coordinator->activeRequest());
        self::assertFalse($coordinator->actionRequired());

        // Queued items should be cleared — enqueue fresh works
        $coordinator->enqueue(new QuestionRequest(
            requestId: 'q3',
            source: QuestionSource::Tui,
            kind: QuestionKind::Text,
            prompt: 'New?',
        ));
        self::assertSame('q3', $coordinator->activeRequest()?->requestId);
    }

    public function testSwitchCancelsActiveRun(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects(self::once())
            ->method('cancel')
            ->with('old-run-id');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'running');

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $client, $state);

        $service->requestResume('42');

        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertSame('42', $target->sessionId);
    }

    public function testSwitchWithoutActiveRunDoesNotThrow(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $state = new TuiSessionState('old', false);
        // No handle — no active run

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), $state);

        // Should not throw
        $service->requestResume('42');
        self::assertTrue($service->hasPendingSwitch());
    }

    public function testSwitchCallsProjectorReset(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);

        $projector = $this->createMock(TranscriptProjectorInterface::class);
        $projector->expects(self::once())
            ->method('reset');

        $tui = new Tui();

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), new TuiSessionState('old', false));

        $service->requestResume('42');
    }

    /**
     * @return array<string, array{RunActivityStateEnum}>
     */
    public static function terminalActivityStates(): array
    {
        return [
            'completed' => [RunActivityStateEnum::Completed],
            'failed' => [RunActivityStateEnum::Failed],
            'cancelled' => [RunActivityStateEnum::Cancelled],
        ];
    }

    /**
     * Terminal runs must never be cancelled — sending cancel to an
     * already-terminal run would transition it to Cancelling and poison
     * the run state, blocking all future resume / follow_up / steer
     * commands.  The switch must still proceed (pending target set).
     */
    #[DataProvider('terminalActivityStates')]
    public function testResumeSkipsCancelForTerminalRun(RunActivityStateEnum $activity): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $client = $this->createMock(AgentSessionClient::class);
        // Expect cancel to NEVER be called for terminal runs
        $client->expects(self::never())->method('cancel');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'completed');
        $state->activity = $activity;

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $client, $state);

        $service->requestResume('42');

        self::assertTrue($service->hasPendingSwitch());
        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertSame('42', $target->sessionId);
    }

    #[DataProvider('terminalActivityStates')]
    public function testNewDraftSkipsCancelForTerminalRun(RunActivityStateEnum $activity): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $client = $this->createMock(AgentSessionClient::class);
        // Expect cancel to NEVER be called for terminal runs
        $client->expects(self::never())->method('cancel');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'completed');
        $state->activity = $activity;

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $client, $state);

        $service->requestNewDraft();

        self::assertTrue($service->hasPendingSwitch());
        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertTrue($target->isDraft);
    }

    public function testSwitchProceedsWhenCancelFails(): void
    {
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        // Client whose cancel() throws — simulating a terminal run that
        // cannot be cancelled (e.g. process already exited).
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects(self::once())
            ->method('cancel')
            ->with('old-run-id')
            ->willThrowException(new \RuntimeException('Run already finished'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                self::stringContains('Session switch'),
                self::callback(static fn (array $c) => 'old-run-id' === $c['run_id']
                    && 'switch_cancel_failed' === ($c['event_type'] ?? null)),
            );

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'running');

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $logger);
        $service->bindForIteration($tui, $client, $state);

        // Should not throw — switch must proceed
        $service->requestResume('42');
        self::assertTrue($service->hasPendingSwitch());

        $target = $service->consumePendingSwitch();
        self::assertNotNull($target);
        self::assertSame('42', $target->sessionId);
    }

    // ── rewindToTurn ────────────────────────────────────────────────────

    public function testRewindToTurnSendsCommand(): void
    {
        // Thesis: rewindToTurn cancels the current run and sends
        // a rewind_to_turn UserCommand with the correct turn_no.
        $coordinator = $this->createCoordinator();
        $controller = $this->createController($coordinator);
        $projector = $this->createStub(TranscriptProjectorInterface::class);
        $tui = new Tui();

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects(self::once())
            ->method('cancel')
            ->with('test-run-id');
        $client->expects(self::once())
            ->method('send')
            ->with(
                'test-run-id',
                self::callback(static fn (UserCommand $cmd): bool =>
                    'rewind_to_turn' === $cmd->type
                    && ['turn_no' => 3] === $cmd->payload
                ),
            );

        $state = new TuiSessionState('test', false);
        $state->handle = new RunHandle('test-run-id', 'running');

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector, $this->createStub(LoggerInterface::class));
        $service->bindForIteration($tui, $client, $state);

        $service->rewindToTurn(3);
    }

    public function testRewindToTurnWithoutHandleThrows(): void
    {
        // Thesis: calling rewindToTurn without a bound handle raises
        // RuntimeException — not a silent no-op.
        $service = $this->createService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot rewind');
        $service->rewindToTurn(1);
    }
}
