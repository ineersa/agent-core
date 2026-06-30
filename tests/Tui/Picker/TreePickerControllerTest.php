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

        self::assertFalse($controller->isOpen());
    }

    // ── buildItems: linear tree ─────────────────────────────────────────────

    #[Test]
    public function testBuildItemsLinearTree(): void
    {
        $tree = $this->createLinearTree();

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        self::assertCount(2, $items, 'Linear tree should produce 2 items');

        // Item 0: root turn (flat, leaf marker ○, title preview)
        self::assertStringContainsString('○ Turn 1:', $items[0]['label']);
        self::assertStringContainsString('Hello', $items[0]['label']);
        self::assertSame('1', $items[0]['value']);
        self::assertArrayNotHasKey('description', $items[0]);

        // Item 1: child (flat — linear only-child chain has no connectors)
        self::assertStringContainsString('◉ Turn 2:', $items[1]['label']);
        self::assertStringContainsString('Follow-up', $items[1]['label']);
        self::assertSame('2', $items[1]['value']);

        // Proof: linear tree must have zero connector glyphs in every label
        foreach ($items as $item) {
            self::assertStringNotContainsString('└─', $item['label'], 'Linear label should not contain └─');
            self::assertStringNotContainsString('├─', $item['label'], 'Linear label should not contain ├─');
            self::assertStringNotContainsString('│', $item['label'], 'Linear label should not contain │');
        }
    }

    #[Test]
    public function testBuildItemsMarksCurrentLeaf(): void
    {
        // Turn 3 is the current leaf
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2],
                anchorSeq: 2,
                title: 'Initial prompt "Hello"',
                promptPreview: 'Hello',
                createdAt: new \DateTimeImmutable('2026-01-01T00:00:00'),
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [3],
                anchorSeq: 5,
                title: 'Follow-up about routing',
                promptPreview: 'Follow-up',
                createdAt: new \DateTimeImmutable('2026-01-01T00:01:00'),
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 2,
                childTurnNos: [],
                anchorSeq: 8,
                title: 'Final response',
                promptPreview: 'Final',
                createdAt: new \DateTimeImmutable('2026-01-01T00:02:00'),
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 3,
            activePathTurnNos: [1, 2, 3],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        self::assertCount(3, $items);

        // First turn: leaf marker ○ (not current leaf)
        self::assertStringContainsString('○ Turn 1:', $items[0]['label']);
        // Second turn: leaf marker ○ (not current leaf)
        self::assertStringContainsString('○ Turn 2:', $items[1]['label']);
        // Third turn: leaf marker ◉ (current leaf)
        self::assertStringContainsString('◉ Turn 3:', $items[2]['label']);

        // Proof: linear 3-turn tree has no connector glyphs in any label
        foreach ($items as $item) {
            self::assertStringNotContainsString('└─', $item['label'], 'Linear label should not contain └─');
            self::assertStringNotContainsString('├─', $item['label'], 'Linear label should not contain ├─');
            self::assertStringNotContainsString('│', $item['label'], 'Linear label should not contain │');
        }
    }

    // ── buildItems: branched tree ───────────────────────────────────────────

    #[Test]
    public function testBuildItemsBranchedTree(): void
    {
        // Root turn 1 → children turn 2 (abandoned) and turn 3 (current)
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2, 3],
                anchorSeq: 2,
                title: 'Initial prompt',
                promptPreview: 'Initial',
                createdAt: new \DateTimeImmutable('2026-01-01T00:00:00'),
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 5,
                title: 'Abandoned branch',
                promptPreview: 'Abandoned',
                createdAt: new \DateTimeImmutable('2026-01-01T00:01:00'),
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 8,
                title: 'Active branch',
                promptPreview: 'Active',
                createdAt: new \DateTimeImmutable('2026-01-01T00:02:00'),
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 3,
            activePathTurnNos: [1, 3],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        self::assertCount(3, $items);

        // Root (flat, ○ — no connector)
        self::assertStringContainsString('○ Turn 1:', $items[0]['label']);
        self::assertSame('1', $items[0]['value']);
        self::assertStringNotContainsString('├─', $items[0]['label']);
        self::assertStringNotContainsString('└─', $items[0]['label']);

        // First sibling (├─ ○, not last child)
        self::assertStringContainsString('├─ ○ Turn 2:', $items[1]['label']);
        self::assertSame('2', $items[1]['value']);

        // Last sibling (└─ ◉, current leaf)
        self::assertStringContainsString('└─ ◉ Turn 3:', $items[2]['label']);
        self::assertSame('3', $items[2]['value']);
    }

    // ── buildItems: branch connectors ────────────────────────────────────────

    #[Test]
    public function testBuildItemsBranchedTreeWithConnectors(): void
    {
        // Deep branching: T1 has two children [T2, T3]; T2 has one child T4 (current leaf).
        // This proves all three connector types: ├─, │  └─, └─.
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2, 3],
                anchorSeq: 2,
                title: 'Root turn',
                promptPreview: 'Root',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [4],
                anchorSeq: 5,
                title: 'Branch A',
                promptPreview: 'A',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 8,
                title: 'Branch B',
                promptPreview: 'B',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            4 => new TurnTreeNodeView(
                turnNo: 4,
                parentTurnNo: 2,
                childTurnNos: [],
                anchorSeq: 11,
                title: 'Deep child',
                promptPreview: 'Deep',
                createdAt: null,
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 4,
            activePathTurnNos: [1, 2, 4],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        self::assertCount(4, $items);

        // T1 (root, []): flat — no branching at root level
        self::assertStringContainsString('○ Turn 1:', $items[0]['label']);
        self::assertStringNotContainsString('├─', $items[0]['label']);
        self::assertStringNotContainsString('└─', $items[0]['label']);

        // T2 (first child of T1, [false]): ├─
        self::assertStringContainsString('├─ ○ Turn 2:', $items[1]['label']);
        self::assertSame('2', $items[1]['value']);

        // T4 (only child of T2, [false, true]): │  └─
        self::assertStringContainsString('│  └─ ◉ Turn 4:', $items[2]['label']);
        self::assertSame('4', $items[2]['value']);

        // T3 (last child of T1, [true]): └─
        self::assertStringContainsString('└─ ○ Turn 3:', $items[3]['label']);
        self::assertSame('3', $items[3]['value']);
    }

    // ── buildItems: empty tree ──────────────────────────────────────────────

    #[Test]
    public function testBuildItemsEmptyTree(): void
    {
        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: [],
            rootTurnNos: [],
            currentLeafTurnNo: null,
            activePathTurnNos: [],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        self::assertSame([], $items);
    }

    // ── buildItems: title truncation ────────────────────────────────────────

    #[Test]
    public function testBuildItemsTruncatesLongTitles(): void
    {
        $longTitle = str_repeat('A very long title that should definitely be truncated by mb_strimwidth ', 3);
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [],
                anchorSeq: 2,
                title: $longTitle,
                promptPreview: $longTitle,
                createdAt: null,
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 1,
            activePathTurnNos: [1],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        self::assertCount(1, $items);
        self::assertLessThan(\strlen($longTitle), \strlen($items[0]['label']), 'Title should be truncated');
        self::assertStringContainsString('…', $items[0]['label'], 'Truncation ellipsis should be present');
    }

    // ── buildItems: accent selected index ────────────────────────────────────

    #[Test]
    public function testBuildItemsAccentsSelectedIndex(): void
    {
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2],
                anchorSeq: 2,
                title: 'Turn one',
                promptPreview: 'One',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 5,
                title: 'Turn two',
                promptPreview: 'Two',
                createdAt: null,
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 2,
            activePathTurnNos: [1, 2],
        );

        $palette = new ThemePalette('test', ['accent' => '#FF00FF']);
        $theme = new DefaultTheme($palette);
        $items = TreePickerController::buildItems($tree, $theme, selectedIndex: 0);

        self::assertStringContainsString("\x1b", $items[0]['label'], 'Selected item should have ANSI accent');
        self::assertStringNotContainsString("\x1b", $items[1]['label'], 'Unselected item should not have ANSI accent');
    }

    // ── open/close lifecycle ────────────────────────────────────────────────

    #[Test]
    public function testOpenMountsOverlayWithTree(): void
    {
        $tree = $this->createLinearTree();
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        self::assertFalse($controller->isOpen());

        $controller->open();

        self::assertTrue($controller->isOpen());
    }

    #[Test]
    public function testOpenShowsStatusWhenEmpty(): void
    {
        $tree = new TurnTreeView(
            runId: 'run',
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

        self::assertFalse($controller->isOpen(), 'Picker should not open for empty tree');
    }

    #[Test]
    public function testCloseResetsState(): void
    {
        $tree = $this->createLinearTree();
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        $controller->open();
        self::assertTrue($controller->isOpen());

        $controller->closePicker();
        self::assertFalse($controller->isOpen());
    }

    #[Test]
    public function testCloseIsIdempotent(): void
    {
        $tree = $this->createLinearTree();
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $switcher = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        $controller->open();
        $controller->closePicker();
        $controller->closePicker(); // second call — should be no-op

        self::assertFalse($controller->isOpen());
    }

    // ── flattenTurnOrder ────────────────────────────────────────────────────

    #[Test]
    public function testFlattenTurnOrderLinear(): void
    {
        $tree = $this->createLinearTree();
        $order = TreePickerController::flattenTurnOrder($tree);

        self::assertSame([1, 2], $order);
    }

    #[Test]
    public function testFlattenTurnOrderBranched(): void
    {
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1, parentTurnNo: null, childTurnNos: [2, 3],
                anchorSeq: 2, title: 'Root', promptPreview: '', createdAt: null, isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2, parentTurnNo: 1, childTurnNos: [],
                anchorSeq: 5, title: 'Branch A', promptPreview: '', createdAt: null, isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3, parentTurnNo: 1, childTurnNos: [],
                anchorSeq: 8, title: 'Branch B', promptPreview: '', createdAt: null, isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 3,
            activePathTurnNos: [1, 3],
        );

        $order = TreePickerController::flattenTurnOrder($tree);

        self::assertSame([1, 2, 3], $order, 'Depth-first: root → branch A → branch B');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    // ── onSelect behavior ───────────────────────────────────────────

    #[Test]
    public function testOnSelectNonCurrentLeafCallsRewind(): void
    {
        // Thesis: selecting a non-current turn in the picker calls
        // switcher->rewindToTurn with the selected turn number and
        // closes the picker.
        $tree = $this->createLinearTree(); // T1 → T2, currentLeaf=2
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switcher->expects(self::once())
            ->method('rewindToTurn')
            ->with(1);

        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        $controller->open();
        self::assertTrue($controller->isOpen());

        // The picker opens with turn 1 (non-current leaf) at index 0.
        // Press Enter to confirm selection (handleInput with \r = ENTER key).
        $controller->overlay()->listWidget()->handleInput("\r");

        self::assertFalse($controller->isOpen(), 'Picker must close after selection');
    }

    #[Test]
    public function testOnSelectCurrentLeafIsNoOp(): void
    {
        // Thesis: selecting the current leaf closes the picker but
        // does NOT call rewindToTurn.
        $tree = $this->createLinearTree(); // T1 → T2, currentLeaf=2
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($tree);

        $switcher = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switcher->expects(self::never())
            ->method('rewindToTurn');

        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        $controller->open();
        self::assertTrue($controller->isOpen());

        // Move selection to turn 2 (current leaf) at index 1, then confirm.
        $widget = $controller->overlay()->listWidget();
        $widget->setSelectedIndex(1);
        $widget->handleInput("\r");

        self::assertFalse($controller->isOpen(), 'Picker must close after selection');
    }

    // ── Helpers ────────────────────────────────────────────────────────

    private function createLinearTree(): TurnTreeView
    {
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2],
                anchorSeq: 2,
                title: 'Initial prompt "Hello world"',
                promptPreview: 'Hello world',
                createdAt: new \DateTimeImmutable('2026-01-01T00:00:00'),
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 5,
                title: 'Follow-up about routing',
                promptPreview: 'Follow-up',
                createdAt: new \DateTimeImmutable('2026-01-01T00:01:00'),
                isCurrentLeaf: true,
            ),
        ];

        return new TurnTreeView(
            runId: 'test-session',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 2,
            activePathTurnNos: [1, 2],
        );
    }
}
