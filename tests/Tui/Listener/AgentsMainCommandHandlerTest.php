<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Projection\TranscriptProjectionState;
use Ineersa\CodingAgent\Runtime\ProjectionPipeline\TranscriptProjector;
use Ineersa\CodingAgent\Tests\Support\SubagentProgressSerializerTestSupport;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\AgentsMainCommandHandler;
use Ineersa\Tui\Question\QuestionController;
use Ineersa\Tui\Question\QuestionCoordinator;
use Ineersa\Tui\Runtime\SubagentLiveChildDTO;
use Ineersa\Tui\Runtime\SubagentLiveChildViewPoller;
use Ineersa\Tui\Runtime\SubagentLiveStatusEnum;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

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
            'deepseek/deepseek-v4-flash',
            'medium'));
        $state->subagentLiveView->lastLiveWorkingMessage = 'Child agent working...';

        $screen = $this->screen();
        $screen->setStatus('agents-live', 'stale live text');
        $screen->setWorkingMessage('Child agent working...');

        $handler = $this->handler($state, $screen);
        $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));

        $this->assertFalse($state->subagentLiveView->active);
        $this->assertNull($this->statusText($screen, 'agents-live'));
        $this->assertArrayNotHasKey('agents-live', $this->allStatusEntries($screen));
    }

    public function testAgentsMainNoOpWhenNotInLiveView(): void
    {
        $state = new TuiSessionState('parent-1');
        $screen = $this->screen();
        $screen->setStatus('agents-live', 'stale live text');

        $handler = $this->handler($state, $screen);
        $handler->handle(new SlashCommand('agents-main', '', '/agents-main'));

        $this->assertFalse($state->subagentLiveView->active);
        $this->assertSame('stale live text', $this->statusText($screen, 'agents-live'));
    }

    private function handler(TuiSessionState $state, ChatScreen $screen): AgentsMainCommandHandler
    {
        $coordinator = new QuestionCoordinator();
        $controller = new QuestionController($coordinator, $screen);

        return new AgentsMainCommandHandler(
            $state,
            $screen,
            $coordinator,
            $controller,
            new SubagentLiveChildViewPoller(
                new TranscriptProjector(new EventDispatcher(), new TranscriptProjectionState()),
                new NullLogger(),
                SubagentProgressSerializerTestSupport::denormalizer(),
            ),
        );
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
        $ref = new \ReflectionProperty(ChatScreen::class, 'statusEntries');

        /** @var array<string, string> $entries */
        $entries = $ref->getValue($screen);

        return $entries;
    }
}
