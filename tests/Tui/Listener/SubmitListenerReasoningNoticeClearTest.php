<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Listener\PromptHistory;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\SubmitEvent;
use Symfony\Component\Tui\Tui;

/**
 * Test thesis: starting a user turn clears the transient status-panel reasoning
 * notice (Shift+Tab) without resetting footerReasoning or footer reasoning styling.
 */
final class SubmitListenerReasoningNoticeClearTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testSubmitClearsTransientReasoningNoticeButKeepsSelectedReasoning(): void
    {
        $state = new TuiSessionState('reasoning-clear-session');
        $state->handle = new RunHandle('run-1');
        $state->activity = RunActivityStateEnum::Completed;
        $state->footerReasoning = 'minimal';

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())
            ->method('send')
            ->with('run-1', $this->callback(static function (UserCommand $cmd): bool {
                return 'follow_up' === $cmd->type && 'next turn' === $cmd->text;
            }));

        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);
        $screen = $harness->screen();
        $screen->addFooterProvider(new FooterStateSegmentProvider($state));

        // Mirror ModelControlListener: panel-only reasoning entry (not footer status map).
        $screen->registry()->setStatus('reasoning', 'minimal');
        $screen->refresh();
        $harness->render();
        $before = $harness->plainScreenText();
        $this->assertStringContainsString('reasoning', $before);
        $this->assertStringContainsString('minimal', $before);

        $tui = $harness->tui();
        $this->registerSubmitListener($client, $state, $screen, $tui);

        $screen->promptEditor()->setText('next turn');
        $this->fireSubmit($screen, $tui);

        $this->assertSame('minimal', $state->footerReasoning);
        $this->assertArrayNotHasKey('reasoning', $this->statusEntries($screen));

        $harness->render();
        $after = $harness->plainScreenText();
        $this->assertStringNotContainsString('  reasoning', $after, 'Transient status-panel notice should be gone');
        $this->assertStringContainsString('◆', $after, 'Footer should still render');
    }

    #[Test]
    public function testAbortedImagePromotionLeavesTransientReasoningNotice(): void
    {
        $state = new TuiSessionState('reasoning-abort-session');
        $state->handle = new RunHandle('run-1');
        $state->activity = RunActivityStateEnum::Completed;
        $state->pastedImagePasteInProgressIndex = 1;

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');

        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);
        $screen = $harness->screen();
        $screen->registry()->setStatus('reasoning', 'high');
        $screen->refresh();

        $tui = $harness->tui();
        $this->registerSubmitListener($client, $state, $screen, $tui);

        $screen->promptEditor()->setText('describe [Image #1]');
        $this->fireSubmit($screen, $tui, 'describe [Image #1]');

        $this->assertArrayHasKey('reasoning', $this->statusEntries($screen));
        $this->assertSame('high', $this->statusEntries($screen)['reasoning']);
    }

    private function registerSubmitListener(
        AgentSessionClient $client,
        TuiSessionState $state,
        ChatScreen $screen,
        Tui $tui,
    ): void {
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->build();

        $listener = new SubmitListener(
            sessionStore: $context->sessionStore,
            submissionRouter: new SubmissionRouter(
                new \Ineersa\Tui\Command\CommandParser(),
                new \Ineersa\Tui\Command\SlashCommandRegistry(),
            ),
            blockFactory: new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
            coordinator: new QuestionCoordinator(),
            questionController: new QuestionController(new QuestionCoordinator()),
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: new NullLogger(),
            history: new PromptHistory(),
            pastedImageSubmissionService: new \Ineersa\Tui\ImagePaste\PastedImageSubmissionService(
                new \Ineersa\Tui\ImagePaste\PastedImageValidationService(
                    new \Ineersa\CodingAgent\Config\ImageToolConfig(),
                    new \Ineersa\AgentCore\Tests\Support\TestLogger(),
                ),
                $context->sessionStore,
                new \Ineersa\CodingAgent\Config\AppConfig(
                    tui: new \Ineersa\CodingAgent\Config\TuiConfig(theme: 'default'),
                    logging: new \Ineersa\CodingAgent\Config\LoggingConfig(),
                    sessions: new \Ineersa\CodingAgent\Config\SessionsConfig(),
                    cwd: '/tmp',
                ),
                new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
                new \Ineersa\AgentCore\Tests\Support\TestLogger(),
            ),
        );
        $listener->register($context);
    }

    private function fireSubmit(ChatScreen $screen, Tui $tui, string $text = 'next turn'): void
    {
        $listeners = $tui->getEventDispatcher()->getListeners(SubmitEvent::class);
        $this->assertNotEmpty($listeners);
        ($listeners[0])(new SubmitEvent($screen->editorWidget(), $text));
    }

    /** @return array<string, string> */
    private function statusEntries(ChatScreen $screen): array
    {
        return $screen->registry()->getStatusEntries();
    }
}
