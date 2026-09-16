<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Listener\HistoryCommandHandler;
use Ineersa\Tui\Listener\SubmitListener;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
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
 * One-shot command notices clear on submission. Reasoning notices clear only
 * after turn validation, without resetting footerReasoning or its styling.
 */
final class SubmitListenerReasoningNoticeClearTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testNextSubmitClearsCommandNoticeAndKeepsPersistentStatus(): void
    {
        $state = new TuiSessionState('transient-clear-session');
        $state->handle = new RunHandle('run-1');
        $state->activity = RunActivityStateEnum::Completed;
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->once())->method('send');
        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);
        $screen = $harness->screen();
        $screen->setStatus('om', 'poller alive');
        $screen->setTransientStatus('history', 'Session has no user prompts yet');
        $this->assertStringContainsString('Session has no user prompts yet', $harness->plainScreenText());

        $tui = $harness->tui();
        $this->registerSubmitListener($client, $state, $screen, $tui);
        $tui->setFocus($screen->editorWidget());
        $tui->handleInput('hello again');
        $tui->handleInput("\r");

        $after = $harness->plainScreenText();
        $this->assertStringNotContainsString('Session has no user prompts yet', $after);
        $this->assertStringContainsString('poller alive', $after);
    }

    #[Test]
    public function testCommandReplacesPreviousNoticeButTypingAndEmptySubmitDoNot(): void
    {
        $state = new TuiSessionState('transient-repost-session');
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn(new HistoryView(prompts: [], positionTurnNo: 0));
        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);
        $screen = $harness->screen();
        $tui = $harness->tui();
        $picker = new HistoryPickerController($tui, $screen, $state, $provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $screen->setTransientStatus('rewind', 'File rewind requires an active session.');
        $catalog = new SlashCommandCatalog();
        (new \Ineersa\Tui\Listener\HistoryCommandRegistrar())->registerCatalog($catalog);
        $registry = new SlashCommandRegistry($catalog);
        $registry->bind('history', new HistoryCommandHandler($picker));
        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $this->registerSubmitListener($client, $state, $screen, $tui, new SubmissionRouter(new CommandParser(), $registry));

        $tui->setFocus($screen->editorWidget());
        $tui->handleInput("\r");
        $this->assertStringContainsString('File rewind requires an active session.', $harness->plainScreenText());
        $tui->handleInput('/history');
        $this->assertStringContainsString('File rewind requires an active session.', $harness->plainScreenText());
        $tui->handleInput("\r");
        $text = $harness->plainScreenText();
        $this->assertStringNotContainsString('File rewind requires an active session.', $text);
        $this->assertStringContainsString('Session has no user prompts yet', $text);
        $tui->handleInput('/unknown-command');
        $tui->handleInput("\r");
        $this->assertStringNotContainsString('Session has no user prompts yet', $harness->plainScreenText());
    }

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
        $screen->setStatus('reasoning', 'minimal');
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
        $screen->setStatus('reasoning', 'high');

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
        ?SubmissionRouter $router = null,
    ): void {
        $context = $this->buildTuiContext()
            ->withTui($tui)
            ->withClient($client)
            ->withState($state)
            ->withScreen($screen)
            ->withSessionServices($this->createSessionServices(
                tui: $tui,
                state: $state,
                screen: $screen,
                submissionRouter: $router ?? new SubmissionRouter(
                    new CommandParser(),
                    new SlashCommandRegistry(new SlashCommandCatalog()),
                ),
            ))
            ->build();

        $listener = new SubmitListener(
            sessionStore: $context->sessionStore,
            blockFactory: new \Ineersa\Tui\Transcript\TranscriptBlockFactory(),
            subagentLiveInputPolicy: new SubagentLiveInputPolicy(),
            logger: new NullLogger(),
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
        $ref = new \ReflectionProperty(ChatScreen::class, 'statusEntries');

        /** @var array<string, string> $entries */
        $entries = $ref->getValue($screen);

        return $entries;
    }
}
