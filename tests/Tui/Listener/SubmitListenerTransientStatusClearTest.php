<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Command\CommandParser;
use Ineersa\Tui\Command\SlashCommandCatalog;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
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
 * Thesis: setTransientStatus notices clear on the next nonempty submit, while
 * persistent setStatus rows and a notice posted by the current command remain.
 */
final class SubmitListenerTransientStatusClearTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function testNextNonemptySubmitClearsTransientNoticeAndKeepsPersistentStatus(): void
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
        $harness->render();

        $before = $harness->plainScreenText();
        $this->assertStringContainsString('Session has no user prompts yet', $before);
        $this->assertStringContainsString('poller alive', $before);

        $tui = $harness->tui();
        $this->registerSubmitListener($client, $state, $screen, $tui, $this->defaultRouter());

        $screen->promptEditor()->setText('hello again');
        $this->fireSubmit($screen, $tui, 'hello again');

        $entries = $this->statusEntries($screen);
        $this->assertArrayNotHasKey('history', $entries);
        $this->assertSame('poller alive', $entries['om'] ?? null);

        $harness->render();
        $after = $harness->plainScreenText();
        $this->assertStringNotContainsString('Session has no user prompts yet', $after);
        $this->assertStringContainsString('poller alive', $after);
    }

    #[Test]
    public function testCurrentCommandCanPostAFreshTransientNoticeAfterClear(): void
    {
        $state = new TuiSessionState('transient-repost-session');
        $state->handle = new RunHandle('run-1');
        $state->activity = RunActivityStateEnum::Completed;

        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn(new HistoryView(prompts: [], positionTurnNo: 0));

        $harness = new VirtualTuiHarness(sessionId: $state->sessionId);
        $screen = $harness->screen();
        $picker = new HistoryPickerController(
            $harness->tui(),
            $screen,
            $state,
            $provider,
            $this->createStub(TuiSessionSwitchServiceInterface::class),
        );
        $screen->setTransientStatus('rewind', 'File rewind requires an active session.');

        $catalog = new SlashCommandCatalog();
        (new \Ineersa\Tui\Listener\HistoryCommandRegistrar())->registerCatalog($catalog);
        $registry = new SlashCommandRegistry($catalog);
        $registry->bind('history', new HistoryCommandHandler($picker));
        $router = new SubmissionRouter(new CommandParser(), $registry);

        $client = $this->createMock(AgentSessionClient::class);
        $client->expects($this->never())->method('send');
        $this->registerSubmitListener($client, $state, $screen, $harness->tui(), $router);

        $screen->promptEditor()->setText('/history');
        $this->fireSubmit($screen, $harness->tui(), '/history');

        $entries = $this->statusEntries($screen);
        $this->assertArrayNotHasKey('rewind', $entries, 'Previous transient notice must clear before routing');
        $this->assertSame('Session has no user prompts yet', $entries['history'] ?? null, 'Current command notice must remain');

        $harness->render();
        $text = $harness->plainScreenText();
        $this->assertStringNotContainsString('File rewind requires an active session.', $text);
        $this->assertStringContainsString('Session has no user prompts yet', $text);
    }

    #[Test]
    public function testTypingWithoutSubmitLeavesTransientNotice(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'transient-typing-session');
        $screen = $harness->screen();
        $screen->setTransientStatus('history', 'Session has no user prompts yet');
        $harness->render();

        $harness->startInputLoop();
        try {
            $harness->sendInput('abc');
            $entries = $this->statusEntries($screen);
            $this->assertSame('Session has no user prompts yet', $entries['history'] ?? null);
            $this->assertStringContainsString('Session has no user prompts yet', $harness->plainScreenText());
        } finally {
            $harness->stopInputLoop();
        }
    }

    private function defaultRouter(): SubmissionRouter
    {
        return new SubmissionRouter(
            new CommandParser(),
            new SlashCommandRegistry(new SlashCommandCatalog()),
        );
    }

    private function registerSubmitListener(
        AgentSessionClient $client,
        TuiSessionState $state,
        ChatScreen $screen,
        Tui $tui,
        SubmissionRouter $router,
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
                submissionRouter: $router,
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

    private function fireSubmit(ChatScreen $screen, Tui $tui, string $text): void
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
