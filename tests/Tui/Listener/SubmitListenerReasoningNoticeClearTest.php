<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Command\SubagentLiveInputPolicy;
use Ineersa\Tui\Command\SubmissionRouter;
use Ineersa\Tui\Listener\FooterStateSegmentProvider;
use Ineersa\Tui\Listener\SubmitListener;
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
            ->withSessionServices($this->createSessionServices(
                tui: $tui,
                state: $state,
                screen: $screen,
                submissionRouter: new SubmissionRouter(
                    new \Ineersa\Tui\Command\CommandParser(),
                    new \Ineersa\Tui\Command\SlashCommandRegistry(new \Ineersa\Tui\Command\SlashCommandCatalog()),
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
