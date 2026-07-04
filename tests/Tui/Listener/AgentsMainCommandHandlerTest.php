<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Listener\AgentsMainCommandHandler;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\SubagentLiveViewState;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Editor\PromptEditor;
use PHPUnit\Framework\TestCase;

final class AgentsMainCommandHandlerTest extends TestCase
{
    public function testAgentsMainClearsLiveWorkingMessageAndDoesNotUseAgentsLiveStatusKey(): void
    {
        $state = new TuiSessionState('parent-1');
        $state->subagentLiveView->enter(new SubagentLiveChildDTO(
            'child-1',
            'agent_a',
            'scout',
            SubagentLiveStatusEnum::Running,
            'task',
            1,
        ));
        $state->subagentLiveView->lastLiveWorkingMessage = 'Child agent working...';

        $screen = $this->screen();
        $screen->setStatus('agents-live', 'stale live text');
        $screen->setWorkingMessage('Child agent working...');

        $handler = new AgentsMainCommandHandler($state, $screen);
        $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));

        self::assertFalse($state->subagentLiveView->active);
        self::assertNull($this->statusText($screen, 'agents-live'));
        self::assertArrayNotHasKey('agents-live', $this->allStatusEntries($screen));
    }

    public function testAgentsMainNoOpWhenNotInLiveView(): void
    {
        $state = new TuiSessionState('parent-1');
        $screen = $this->screen();
        $screen->setStatus('agents-live', 'stale live text');

        $handler = new AgentsMainCommandHandler($state, $screen);
        $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));

        self::assertFalse($state->subagentLiveView->active);
        self::assertSame('stale live text', $this->statusText($screen, 'agents-live'));
    }

    private function screen(): ChatScreen
    {
        $theme = new DefaultTheme(new ThemePalette('test'));

        return new ChatScreen($theme, 'parent-1', new PromptEditor());
    }

    private function statusText(ChatScreen $screen, string $key): ?string
    {
        $entries = $this->allStatusEntries($screen);

        return $entries[$key] ?? null;
    }

    /** @return array<string, string> */
    private function allStatusEntries(ChatScreen $screen): array
    {
        $ref = new \ReflectionClass(ChatScreen::class);
        $prop = $ref->getProperty('footerDataProvider');

        return $prop->getValue($screen)->getStatusEntries();
    }
}
