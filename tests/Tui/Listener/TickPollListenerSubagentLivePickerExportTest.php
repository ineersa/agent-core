<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\SessionTranscriptProviderInterface;
use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RuntimeQuestionEventHandler;
use Ineersa\Tui\Listener\TickPollListener;
use Ineersa\Tui\Picker\SubagentLivePickerController;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\RuntimeEventPoller;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\TuiRuntimeEventApplier;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Runtime\TuiTickDispatcher;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Tui\Tui;

/** @covers \Ineersa\Tui\Listener\TickPollListener */
final class TickPollListenerSubagentLivePickerExportTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    public function testTickRestoresPickerExportFeedbackInsteadOfWorkingMessage(): void
    {
        $state = new TuiSessionState('session-picker-export');
        $state->handle = new RunHandle('session-picker-export', 'running');
        $state->activity = RunActivityStateEnum::Running;
        $state->subagentLiveView->pickerFeedbackMessage = 'Child agent exported to: /tmp/hatfield-child-agent_x.html';

        $picker = $this->pickerReportingOpen();
        $screen = $this->screenFor('session-picker-export');
        $screen->setWorkingMessage('Working...');

        $this->runOneTick($state, $screen, $picker);

        $msg = $this->workingMessage($screen);
        $this->assertStringContainsString('Child agent exported to:', $msg);
        $this->assertStringNotContainsString('Working...', $msg);
    }

    public function testTickRestoresPickerExportFailureFeedback(): void
    {
        $state = new TuiSessionState('session-picker-fail');
        $state->activity = RunActivityStateEnum::Idle;
        $state->subagentLiveView->pickerFeedbackMessage = 'Session child-run has no events to export.';

        $picker = $this->pickerReportingOpen();
        $screen = $this->screenFor('session-picker-fail');
        $screen->setWorkingMessage('Working...');

        $this->runOneTick($state, $screen, $picker);

        $this->assertSame('Session child-run has no events to export.', $this->workingMessage($screen));
    }

    private function screenFor(string $sessionId): ChatScreen
    {
        return new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            $sessionId,
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
    }

    private function workingMessage(ChatScreen $screen): string
    {
        $ref = new \ReflectionClass($screen);
        $registry = $ref->getProperty('registry');

        return $registry->getValue($screen)->getWorkingMessage();
    }

    private function pickerReportingOpen(): SubagentLivePickerController
    {
        $picker = (new \ReflectionClass(SubagentLivePickerController::class))->newInstanceWithoutConstructor();
        $overlay = new \Ineersa\Tui\Picker\PickerOverlay();
        $overlayRef = new \ReflectionProperty(SubagentLivePickerController::class, 'overlay');
        $overlayRef->setValue($picker, $overlay);
        $openRef = new \ReflectionProperty(\Ineersa\Tui\Picker\PickerOverlay::class, 'isOpen');
        $openRef->setValue($overlay, true);

        return $picker;
    }

    private function runOneTick(TuiSessionState $state, ChatScreen $screen, SubagentLivePickerController $picker): void
    {
        $poller = new RuntimeEventPoller(
            new TuiRuntimeEventApplier(new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState())),
            new NullLogger(),
            new RuntimeExceptionBoundary(new EventDispatcher()),
            $this->createStub(SessionTranscriptProviderInterface::class),
        );

        $listenerRef = new \ReflectionClass(TickPollListener::class);
        $listener = $listenerRef->newInstanceWithoutConstructor();
        $listenerRef->getProperty('subagentLivePickerController')->setValue($listener, $picker);
        $listenerRef->getProperty('poller')->setValue($listener, $poller);
        $listenerRef->getProperty('subagentLiveChildPoller')->setValue($listener, new SubagentLiveChildViewPoller(
            new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
            new NullLogger(),
        ));
        $listenerRef->getProperty('questionCoordinator')->setValue($listener, new QuestionCoordinator());
        $listenerRef->getProperty('questionController')->setValue($listener, (new \ReflectionClass(QuestionController::class))->newInstanceWithoutConstructor());
        $listenerRef->getProperty('runtimeQuestionEventHandler')->setValue($listener, new RuntimeQuestionEventHandler());

        $context = $this->buildTuiContext()
            ->withTui(new Tui())
            ->withClient($this->createStub(AgentSessionClient::class))
            ->withState($state)
            ->withScreen($screen)
            ->build();

        $listener->register($context);
        $handlerRef = new \ReflectionProperty(TuiTickDispatcher::class, 'handlers');
        ($handlerRef->getValue($context->ticks)[0])();
    }
}
