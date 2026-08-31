<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\ReloadCommandHandler;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Question\QuestionKind;
use Ineersa\Tui\Question\QuestionRequest;
use Ineersa\Tui\Question\QuestionSource;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Guard matrix for /reload: every state that would lose transient input or
 * kill an active run must reject with an actionable message; the happy path
 * must carry the current persisted session id to the switch service.
 */
#[CoversClass(ReloadCommandHandler::class)]
final class ReloadCommandHandlerTest extends TestCase
{
    #[Test]
    public function testHappyPathRequestsReloadWithCurrentSessionId(): void
    {
        $switch = $this->createSwitchSpy();
        $handler = new ReloadCommandHandler(
            $switch,
            new TuiSessionState('42', true),
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertSame('42', $switch->reloadedSessionId, 'Reload must carry the current persisted session id');
    }

    #[Test]
    public function testDraftSessionReloadsAsEmptySessionId(): void
    {
        $switch = $this->createSwitchSpy();
        $handler = new ReloadCommandHandler(
            $switch,
            new TuiSessionState('', false),
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertSame('', $switch->reloadedSessionId, 'A draft reload relaunches a fresh draft (no --resume)');
    }

    /**
     * Every active activity state must reject: reloading mid-run would tear
     * the client down under an in-flight run.
     */
    #[DataProvider('activeActivityStates')]
    public function testRejectsActiveRun(RunActivityStateEnum $activity): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->activity = $activity;
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('error', $result->style);
        $this->assertStringContainsString('Cannot reload', $result->text);
        $this->assertNull($switch->reloadedSessionId, 'Switch must not be called on rejection');
    }

    /**
     * @return array<string, array{RunActivityStateEnum}>
     */
    public static function activeActivityStates(): array
    {
        return [
            'starting' => [RunActivityStateEnum::Starting],
            'running' => [RunActivityStateEnum::Running],
            'waiting-human' => [RunActivityStateEnum::WaitingHuman],
            'cancelling' => [RunActivityStateEnum::Cancelling],
        ];
    }

    #[Test]
    public function testRejectsWhileCompacting(): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->isCompacting = true;
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('compaction', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsQueuedFollowUp(): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->queuedFollowUp = 'follow up text';
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('follow-up', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsQueuedUserMessages(): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->queuedUserMessages = ['one', 'two'];
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('queued', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsPendingPastedImages(): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->pastedImagePendingByIndex = [2 => '/tmp/x.png'];
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('pasted image', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsPasteInProgress(): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->pastedImagePasteInProgressIndex = 3;
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('paste', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsPendingEditorPromptText(): void
    {
        $switch = $this->createSwitchSpy();
        $state = new TuiSessionState('42', false);
        $state->pendingEditorPromptText = 'draft';
        $handler = new ReloadCommandHandler(
            $switch,
            $state,
            $this->createScreen(),
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('editor', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsNonEmptyEditorText(): void
    {
        $switch = $this->createSwitchSpy();
        $screen = $this->createScreen();
        $screen->editorWidget()->setText('unsubmitted draft');
        $handler = new ReloadCommandHandler(
            $switch,
            new TuiSessionState('42', false),
            $screen,
            $this->createQuestionCoordinator(actionRequired: false),
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('editor', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    #[Test]
    public function testRejectsOpenQuestion(): void
    {
        $switch = $this->createSwitchSpy();
        $coordinator = new QuestionCoordinator();
        $coordinator->enqueue(new QuestionRequest(
            requestId: 'q-1',
            source: QuestionSource::AgentCore,
            kind: QuestionKind::Confirm,
            prompt: 'Approve?'));
        $handler = new ReloadCommandHandler(
            $switch,
            new TuiSessionState('42', false),
            $this->createScreen(),
            $coordinator,
        );

        $result = $handler->handle(new SlashCommand('reload', '', '/reload'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('question', $result->text);
        $this->assertNull($switch->reloadedSessionId);
    }

    private function createSwitchSpy(): object
    {
        return new class implements TuiSessionSwitchServiceInterface {
            public ?string $reloadedSessionId = null;

            public function requestResume(string $sessionId): void
            {
            }

            public function requestNewDraft(?StartRunRequest $request = null): void
            {
            }

            public function requestReload(string $sessionId): void
            {
                $this->reloadedSessionId = $sessionId;
            }

            public function selectHistoryTurn(int $targetTurnNo): void
            {
            }
        };
    }

    private function createScreen(): ChatScreen
    {
        return new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            new PromptEditor(),
        );
    }

    private function createQuestionCoordinator(bool $actionRequired): QuestionCoordinator
    {
        if ($actionRequired) {
            $coordinator = new QuestionCoordinator();
            $coordinator->enqueue(new QuestionRequest(
                requestId: 'q-1',
                source: QuestionSource::AgentCore,
                kind: QuestionKind::Confirm,
                prompt: 'Approve?'));

            return $coordinator;
        }

        return new QuestionCoordinator();
    }
}
