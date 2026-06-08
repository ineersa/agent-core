<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Application;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\TranscriptProjectorInterface;
use Ineersa\Tui\Application\TuiSessionSwitchService;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
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
    ): TuiSessionSwitchService {
        return new TuiSessionSwitchService(
            $coordinator ?? $this->createCoordinator(),
            $controller ?? $this->createController($coordinator ?? $this->createCoordinator()),
            $projector ?? $this->createStub(TranscriptProjectorInterface::class),
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
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

        $service = new TuiSessionSwitchService($coordinator, $controller, $projector);
        $service->bindForIteration($tui, $this->createStub(AgentSessionClient::class), new TuiSessionState('old', false));

        $service->requestResume('42');
    }
}
