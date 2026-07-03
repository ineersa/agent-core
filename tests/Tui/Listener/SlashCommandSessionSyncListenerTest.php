<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\CommandMetadata;
use Ineersa\Tui\Command\SessionAwareSlashCommandHandler;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\SlashCommandHandler;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\SlashCommandSessionSyncListener;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventDTO;
use Ineersa\Tui\Runtime\TuiSessionLifecycleEventTypeEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

final class SlashCommandSessionSyncListenerTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    #[Test]
    public function sessionResumedLifecycleEventUpdatesActiveSessionForSlashCommands(): void
    {
        $registry = new SlashCommandRegistry();
        $captured = new class implements SlashCommandHandler, SessionAwareSlashCommandHandler {
            public string $sessionId = '';

            public function setSessionId(string $sessionId): void
            {
                $this->sessionId = $sessionId;
            }

            public function handle(SlashCommand $command): TranscriptMessage
            {
                return new TranscriptMessage($this->sessionId, 'system');
            }
        };
        $registry->register(
            new CommandMetadata(name: 'probe', description: 'probe'),
            $captured,
        );

        $context = $this->lifecycleContext();
        (new SlashCommandSessionSyncListener($registry))->register($context);

        $context->lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionResumed,
            sessionId: '42',
            isDraft: false,
            resuming: true,
            previousSessionId: '7',
        ));

        $result = $registry->execute(new SlashCommand('probe', '', '/probe'));
        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertSame('42', $result->text);
    }

    #[Test]
    public function sessionDraftStartedClearsStaleActiveSessionForSlashCommands(): void
    {
        $registry = new SlashCommandRegistry();
        $registry->setActiveSessionId('stale');
        $captured = new class implements SlashCommandHandler, SessionAwareSlashCommandHandler {
            public string $sessionId = 'unset';

            public function setSessionId(string $sessionId): void
            {
                $this->sessionId = $sessionId;
            }

            public function handle(SlashCommand $command): TranscriptMessage
            {
                return new TranscriptMessage($this->sessionId, 'system');
            }
        };
        $registry->register(
            new CommandMetadata(name: 'probe', description: 'probe'),
            $captured,
        );

        $context = $this->lifecycleContext();
        (new SlashCommandSessionSyncListener($registry))->register($context);

        $context->lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionDraftStarted,
            sessionId: '',
            isDraft: true,
            resuming: false,
            previousSessionId: 'stale',
        ));

        $result = $registry->execute(new SlashCommand('probe', '', '/probe'));
        self::assertInstanceOf(TranscriptMessage::class, $result);
        self::assertSame('unset', $result->text);
    }

    private function lifecycleContext(): TuiRuntimeContext
    {
        $tui = new Tui();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $state = new TuiSessionState('1');
        $screen = new ChatScreen($theme, $state->sessionId, new PromptEditor());

        return $this->buildTuiContext()
            ->withTui($tui)
            ->withState($state)
            ->withScreen($screen)
            ->build();
    }
}
