<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Tui;

#[CoversClass(TreePickerController::class)]
final class TreePickerControllerTest extends TestCase
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
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $controller = new TreePickerController($provider, $switcher);

        $this->assertFalse($controller->isOpen());
    }

    /**
     * Thesis: /history must expose user prompts only — assistant/tool turns never appear as rows.
     */
    #[Test]
    public function testBuildItemsUserPromptsOnly(): void
    {
        $tree = $this->createMixedRoleHistory();
        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        $this->assertCount(2, $items);
        $this->assertSame(['1', '3'], array_column($items, 'value'));
        $this->assertStringContainsString('Hello', $items[0]['label']);
        $this->assertStringContainsString('Follow-up', $items[1]['label']);
        foreach ($items as $item) {
            $this->assertStringNotContainsString('Assistant', $item['label']);
            $this->assertStringNotContainsString('├─', $item['label']);
            $this->assertStringNotContainsString('└─', $item['label']);
        }
    }

    #[Test]
    public function testFlattenTurnOrderUserOnly(): void
    {
        $tree = $this->createMixedRoleHistory();
        $this->assertSame([1, 3], TreePickerController::flattenTurnOrder($tree));
    }

    #[Test]
    public function testInitialSelectedIndexPrefersNextUserPromptAfterTip(): void
    {
        // Tip at turn 1 (before second user prompt turn 3).
        $tree = $this->createMixedRoleHistory(currentLeaf: 1);
        $this->assertSame(1, TreePickerController::initialSelectedIndex($tree));
    }

    #[Test]
    public function testBuildItemsEmptyTree(): void
    {
        $tree = new TurnTreeView(
            runId: 'r',
            nodesByTurnNo: [],
            rootTurnNos: [],
            currentLeafTurnNo: null,
            activePathTurnNos: [],
        );
        $theme = new DefaultTheme(new ThemePalette('test'));
        $this->assertSame([], TreePickerController::buildItems($tree, $theme));
    }

    #[Test]
    public function testOpenMountsOverlayWithHistory(): void
    {
        $tree = $this->createMixedRoleHistory();
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);

        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);
        $controller->open();

        $this->assertTrue($controller->isOpen());
        $controller->closePicker();
        $this->assertFalse($controller->isOpen());
    }

    #[Test]
    public function testOpenShowsStatusWhenEmpty(): void
    {
        $tree = new TurnTreeView(
            runId: 'r',
            nodesByTurnNo: [],
            rootTurnNos: [],
            currentLeafTurnNo: null,
            activePathTurnNos: [],
        );
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);
        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);

        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);
        $controller->open();

        $this->assertFalse($controller->isOpen());
    }

    #[Test]
    public function testOnSelectCallsRewindToSelectedPromptTurn(): void
    {
        $tree = $this->createMixedRoleHistory();
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switcher->expects($this->once())->method('rewindToTurn')->with(3);

        $controller = new TreePickerController($provider, $switcher);
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

    private function createMixedRoleHistory(?int $currentLeaf = 3): TurnTreeView
    {
        $n1 = new TurnTreeNodeView(
            turnNo: 1,
            parentTurnNo: null,
            childTurnNos: [2],
            anchorSeq: 2,
            title: 'Hello',
            promptPreview: 'Hello',
            createdAt: null,
            isCurrentLeaf: 1 === $currentLeaf,
            displayRole: 'user',
            fullPromptText: 'Hello',
        );
        $n2 = new TurnTreeNodeView(
            turnNo: 2,
            parentTurnNo: 1,
            childTurnNos: [3],
            anchorSeq: 5,
            title: 'Assistant response',
            promptPreview: 'Assistant response',
            createdAt: null,
            isCurrentLeaf: 2 === $currentLeaf,
            displayRole: 'assistant',
        );
        $n3 = new TurnTreeNodeView(
            turnNo: 3,
            parentTurnNo: 2,
            childTurnNos: [],
            anchorSeq: 8,
            title: 'Follow-up',
            promptPreview: 'Follow-up',
            createdAt: null,
            isCurrentLeaf: 3 === $currentLeaf,
            displayRole: 'user',
            fullPromptText: 'Follow-up',
        );

        return new TurnTreeView(
            runId: 'test-session',
            nodesByTurnNo: [1 => $n1, 2 => $n2, 3 => $n3],
            rootTurnNos: [1],
            currentLeafTurnNo: $currentLeaf,
            activePathTurnNos: [1, 2, 3],
        );
    }
}
