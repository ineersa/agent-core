<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\AgentsMainCommandHandler;
use Ineersa\Tui\Runtime\RunActivityStateEnum;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentsMainCommandHandlerTest extends TestCase
{
    #[Test]
    public function agentsMainClearsAgentsLiveStatusKeyWithNullNotEmptyString(): void
    {
        $state = new TuiSessionState('parent-session');
        $child = new SubagentLiveChildDTO(
            agentRunId: 'child-run-1',
            artifactId: 'agent_a',
            agentName: 'scout',
            status: SubagentLiveStatusEnum::Running,
            taskSummary: 'Task',
            lastActivityAtMs: 1,
        );
        $state->subagentLiveView->enter($child);
        $state->subagentLiveView->childActivity = RunActivityStateEnum::Running;

        $screen = $this->newScreen();
        $screen->setStatus('agents-live', 'Subagent live: scout [running] — type to steer next step; /agents-main to return.');

        $handler = new AgentsMainCommandHandler($state, $screen);
        $result = $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertFalse($state->subagentLiveView->active);
        $this->assertNull($this->statusText($screen, 'agents-live'));
        $this->assertArrayNotHasKey('agents-live', $this->allStatusEntries($screen));
    }

    #[Test]
    public function agentsMainNoOpWhenNotInLiveView(): void
    {
        $state = new TuiSessionState('parent-session');
        $screen = $this->newScreen();
        $screen->setStatus('agents-live', 'stale live text');

        $handler = new AgentsMainCommandHandler($state, $screen);
        $result = $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertSame('stale live text', $this->statusText($screen, 'agents-live'));
    }

    private function newScreen(): ChatScreen
    {
        return new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'parent-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
    }

    private function statusText(ChatScreen $screen, string $key): ?string
    {
        return $this->allStatusEntries($screen)[$key] ?? null;
    }

    /** @return array<string, string> */
    private function allStatusEntries(ChatScreen $screen): array
    {
        $ref = new \ReflectionClass($screen);
        $providerProp = $ref->getProperty('footerDataProvider');
        $data = $providerProp->getValue($screen);

        return $data->getStatusEntries();
    }
}
