<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\SlashCommandSessionSyncListener;
use Ineersa\Tui\Runtime\TuiRuntimeContext;
use Ineersa\Tui\Runtime\TuiSessionLifecycleDispatcher;
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
        $context = $this->lifecycleContext();
        (new SlashCommandSessionSyncListener($registry))->register($context);

        $context->lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionResumed,
            sessionId: '42',
            isDraft: false,
            resuming: true,
            previousSessionId: '7',
        ));

        self::assertSame('42', $registry->getActiveSessionId());
    }

    #[Test]
    public function sessionDraftStartedClearsStaleActiveSessionForSlashCommands(): void
    {
        $registry = new SlashCommandRegistry();
        $registry->setActiveSessionId('stale');
        $context = $this->lifecycleContext();
        (new SlashCommandSessionSyncListener($registry))->register($context);

        $context->lifecycle->dispatch(new TuiSessionLifecycleEventDTO(
            type: TuiSessionLifecycleEventTypeEnum::SessionDraftStarted,
            sessionId: '',
            isDraft: true,
            resuming: false,
            previousSessionId: 'stale',
        ));

        self::assertNull($registry->getActiveSessionId());
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
            ->withLifecycle(new TuiSessionLifecycleDispatcher())
            ->build();
    }
}
