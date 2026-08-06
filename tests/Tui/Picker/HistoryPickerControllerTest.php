<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Runtime\Contract\HistoryProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryPromptView;
use Ineersa\CodingAgent\Runtime\Protocol\HistoryView;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Picker\HistoryPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(HistoryPickerController::class)]
final class HistoryPickerControllerTest extends TestCase
{
    private Tui $tui;
    private ChatScreen $screen;
    private TuiSessionState $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tui = new Tui();
        $promptEditor = new PromptEditor();
        $this->screen = new ChatScreen(
            new DefaultTheme(new ThemePalette('test')),
            'test-session',
            $promptEditor,
        );
        $this->screen->mount($this->tui);
        $this->state = new TuiSessionState(
            sessionId: 'test-session',
            resuming: false,
        );
    }

    #[Test]
    public function testIsOpenIsFalseInitially(): void
    {
        $provider = $this->createStub(HistoryProviderInterface::class);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $controller = new HistoryPickerController($provider, $switcher);

        $this->assertFalse($controller->isOpen());
    }

    /**
     * Thesis: /history renders HistoryView turns directly; SessionHistoryProvider is the
     * single user-prompt filter, so the controller must not re-filter roles.
     */
    #[Test]
    public function testBuildItemsRendersProviderUserPrompts(): void
    {
        $history = $this->createUserPromptHistory();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = HistoryPickerController::buildItems($history, $theme);

        $this->assertCount(2, $items);
        $this->assertSame(['1', '3'], array_column($items, 'value'));
        $this->assertStringContainsString('Hello', $items[0]['label']);
        $this->assertStringContainsString('Follow-up', $items[1]['label']);
        foreach ($items as $item) {
            $this->assertStringNotContainsString('├─', $item['label']);
            $this->assertStringNotContainsString('└─', $item['label']);
        }
    }

    #[Test]
    public function testUserPromptTurnNosMapsHistoryTurns(): void
    {
        $history = $this->createUserPromptHistory();
        $this->assertSame([1, 3], HistoryPickerController::userPromptTurnNos($history));
    }

    #[Test]
    public function testInitialSelectedIndexPrefersNextUserPromptAfterTip(): void
    {
        // Tip at turn 1 (before second user prompt turn 3).
        $history = $this->createUserPromptHistory(positionTurnNo: 1);
        $this->assertSame(1, HistoryPickerController::initialSelectedIndex($history));
    }

    #[Test]
    public function testBuildItemsEmptyHistory(): void
    {
        $history = new HistoryView(runId: 'r', turns: [], positionTurnNo: null);
        $theme = new DefaultTheme(new ThemePalette('test'));
        $this->assertSame([], HistoryPickerController::buildItems($history, $theme));
    }

    #[Test]
    public function testOpenMountsOverlayWithHistory(): void
    {
        $history = $this->createUserPromptHistory();
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);

        $controller = new HistoryPickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);
        $controller->open();

        $this->assertTrue($controller->isOpen());
        $controller->closePicker();
        $this->assertFalse($controller->isOpen());
    }

    #[Test]
    public function testOpenShowsStatusWhenEmpty(): void
    {
        $history = new HistoryView(runId: 'r', turns: [], positionTurnNo: null);
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);

        $controller = new HistoryPickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);
        $controller->open();

        $this->assertFalse($controller->isOpen());
    }

    #[Test]
    public function testOnSelectCallsSelectHistoryTurnForSelectedPrompt(): void
    {
        $history = $this->createUserPromptHistory();
        $provider = $this->createStub(HistoryProviderInterface::class);
        $provider->method('forSession')->willReturn($history);

        $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switcher->expects($this->once())->method('selectHistoryTurn')->with(3);

        $controller = new HistoryPickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);
        $controller->open();

        $overlay = $controller->overlay();
        $this->assertNotNull($overlay);
        $list = $overlay->listWidget();
        $this->assertNotNull($list);
        $list->setSelectedIndex(1);
        // Enter confirms selection (select_confirm keybinding).
        $list->handleInput("\n");
    }

    /** Provider contract: HistoryView turns are already user prompts only. */
    private function createUserPromptHistory(?int $positionTurnNo = 3): HistoryView
    {
        return new HistoryView(
            runId: 'test-session',
            turns: [
                new HistoryPromptView(
                    turnNo: 1,
                    title: 'Hello',
                    displayRole: 'user',
                    promptText: 'Hello',
                    isPosition: 1 === $positionTurnNo,
                ),
                new HistoryPromptView(
                    turnNo: 3,
                    title: 'Follow-up',
                    displayRole: 'user',
                    promptText: 'Follow-up',
                    isPosition: 3 === $positionTurnNo,
                ),
            ],
            positionTurnNo: $positionTurnNo,
        );
    }
}
