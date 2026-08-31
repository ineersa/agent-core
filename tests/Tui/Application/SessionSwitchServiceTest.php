<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\ProcessReloadIntentDTO;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Application\TuiSessionSwitchService;
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
    public function testHasPendingSwitchIsFalseInitially(): void
    {
        $service = $this->createService();

        $this->assertNull($service->consumePendingSwitch());
    }

    public function testConsumePendingSwitchReturnsNullWhenNothingPending(): void
    {
        $service = $this->createService();

        $this->assertNull($service->consumePendingSwitch());
    }

    public function testRequestResumeSetsPendingResumeTarget(): void
    {
        $service = $this->createService();

        $service->requestResume('42');

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertFalse($target->isDraft);
        $this->assertSame('42', $target->sessionId);
        $this->assertNull($target->request);

        // After consume, nothing pending
        $this->assertNull($service->consumePendingSwitch());
        $this->assertNull($service->consumePendingSwitch());
    }

    public function testRequestNewDraftSetsPendingDraftTarget(): void
    {
        $service = $this->createService();

        $service->requestNewDraft();

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertTrue($target->isDraft);
        $this->assertNull($target->sessionId);
        $this->assertNull($target->request);

        $this->assertNull($service->consumePendingSwitch());
    }

    public function testRequestNewDraftWithRequestPassesThrough(): void
    {
        $service = $this->createService();

        $req = new StartRunRequest(prompt: 'from /new', runId: '');
        $service->requestNewDraft($req);

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertTrue($target->isDraft);
        $this->assertSame($req, $target->request);
    }

    /**
     * Fresh-ownership: each iteration gets its own switch service; pending
     * switch state is instance-local and never shared.
     */
    public function testServiceInstancesAreIndependentPerIteration(): void
    {
        $serviceA = $this->createService();
        $serviceB = $this->createService(new TuiSessionState('other', false));

        $serviceA->requestResume('42');

        $this->assertNull($serviceB->consumePendingSwitch());
        $this->assertNull($serviceB->consumePendingSwitch());

        $target = $serviceA->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertSame('42', $target->sessionId);
    }

    public function testSwitchCancelsActiveRun(): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('cancel')
            ->with('old-run-id');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'running');

        $service = $this->createService($state, $client);

        $service->requestResume('42');

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertSame('42', $target->sessionId);
    }

    public function testSwitchWithoutActiveRunDoesNotThrow(): void
    {
        $state = new TuiSessionState('old', false);
        // No handle — no active run

        $service = $this->createService($state);

        // Should not throw
        $service->requestResume('42');
        $this->addToAssertionCount(1);
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
        $client = $this->createMock(AgentSessionClient::class);
        // Expect cancel to NEVER be called for terminal runs
        $client->expects($this->never())->method('cancel');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'completed');
        $state->activity = $activity;

        $service = $this->createService($state, $client);

        $service->requestResume('42');

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertSame('42', $target->sessionId);
    }

    #[DataProvider('terminalActivityStates')]
    public function testNewDraftSkipsCancelForTerminalRun(RunActivityStateEnum $activity): void
    {
        $client = $this->createMock(AgentSessionClient::class);
        // Expect cancel to NEVER be called for terminal runs
        $client->expects($this->never())->method('cancel');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'completed');
        $state->activity = $activity;

        $service = $this->createService($state, $client);

        $service->requestNewDraft();

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertTrue($target->isDraft);
    }

    public function testSwitchProceedsWhenCancelFails(): void
    {
        // Client whose cancel() throws — simulating a terminal run that
        // cannot be cancelled (e.g. process already exited).
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('cancel')
            ->with('old-run-id')
            ->willThrowException(new \RuntimeException('Run already finished'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('Session switch'),
                $this->callback(static fn (array $c) => 'old-run-id' === $c['run_id']
                    && 'switch_cancel_failed' === ($c['event_type'] ?? null)),
            );

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'running');

        $service = $this->createService($state, $client, $logger);

        // Should not throw — switch must proceed
        $service->requestResume('42');

        $target = $service->consumePendingSwitch();
        $this->assertNotNull($target);
        $this->assertSame('42', $target->sessionId);
    }

    // ── selectHistoryTurn ────────────────────────────────────────────────────

    public function testSelectHistoryTurnSendsCommand(): void
    {
        // Thesis: selectHistoryTurn cancels the current run and sends
        // a select_history_turn UserCommand with the correct turn_no.
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('cancel')
            ->with('test-run-id');
        $client->expects($this->once())
            ->method('send')
            ->with(
                'test-run-id',
                $this->callback(static fn (UserCommand $cmd): bool => 'select_history_turn' === $cmd->type
                    && ['turn_no' => 3] === $cmd->payload
                ),
            );

        $state = new TuiSessionState('test', false);
        $state->handle = new RunHandle('test-run-id', 'running');

        $service = $this->createService($state, $client);

        $service->selectHistoryTurn(3);
    }

    public function testSelectHistoryTurnWithoutHandleThrows(): void
    {
        // Thesis: calling selectHistoryTurn without a run handle raises
        // RuntimeException — not a silent no-op.
        $service = $this->createService();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot select history');
        $service->selectHistoryTurn(1);
    }

    // ── requestReload / consumePendingReload ────────────────────────────────

    public function testConsumePendingReloadReturnsNullWhenNothingPending(): void
    {
        $service = $this->createService();

        $this->assertNull($service->consumePendingReload());
    }

    public function testRequestReloadStopsTuiAndCarriesSessionId(): void
    {
        $service = $this->createService();

        $service->requestReload('42');

        $intent = $service->consumePendingReload();
        $this->assertNotNull($intent);
        $this->assertInstanceOf(ProcessReloadIntentDTO::class, $intent);
        $this->assertSame('42', $intent->sessionId);

        // A reload is NOT a session switch — no switch target, no pending flag.
        $this->assertNull($service->consumePendingSwitch());
        $this->assertNull($service->consumePendingSwitch());
        $this->assertNull($service->consumePendingReload());
    }

    public function testRequestReloadNeverCancelsCurrentRun(): void
    {
        // Reload is guarded to idle/terminal by ReloadCommandHandler; even if
        // a handle/activity is present, requestReload must not cancel — the
        // teardown happens via shutdown() on the reload path instead.
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('cancel');

        $state = new TuiSessionState('old', false);
        $state->handle = new RunHandle('old-run-id', 'running');
        $state->activity = RunActivityStateEnum::Running;

        $service = $this->createService($state, $client);

        $service->requestReload('42');

        $intent = $service->consumePendingReload();
        $this->assertNotNull($intent);
        $this->assertSame('42', $intent->sessionId);
    }

    public function testReloadWinsOverStaleSwitch(): void
    {
        // InteractiveMode consumes the reload intent BEFORE the switch target;
        // a stale switch left over in the same iteration must not win.
        $service = $this->createService();

        $service->requestResume('42');
        $service->requestReload('43');

        $intent = $service->consumePendingReload();
        $this->assertNotNull($intent);
        $this->assertSame('43', $intent->sessionId);
    }

    private function createService(
        ?TuiSessionState $state = null,
        ?AgentSessionClient $client = null,
        ?LoggerInterface $logger = null,
    ): TuiSessionSwitchService {
        return new TuiSessionSwitchService(
            new Tui(),
            $client ?? $this->createStub(AgentSessionClient::class),
            $state ?? new TuiSessionState('test', false),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
