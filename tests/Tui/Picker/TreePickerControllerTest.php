<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Picker\TreePickerController;
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
        $controller = new TreePickerController($provider);

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

        // Item 0: root turn (no indent, leaf marker ○, title preview)
        self::assertStringContainsString('○ Turn 1:', $items[0]['label']);
        self::assertStringContainsString('Hello', $items[0]['label']);
        self::assertSame('1', $items[0]['value']);
        self::assertStringContainsString('2026-01-01', $items[0]['description']);

        // Item 1: child (indented, leaf marker ◉ — turn 2 is current leaf)
        self::assertStringContainsString('└─ ◉ Turn 2:', $items[1]['label']);
        self::assertStringContainsString('Follow-up', $items[1]['label']);
        self::assertSame('2', $items[1]['value']);
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

        // Root (no indent, ○)
        self::assertStringContainsString('○ Turn 1:', $items[0]['label']);
        self::assertSame('1', $items[0]['value']);

        // Abandoned child (indented, ○)
        self::assertStringContainsString('└─ ○ Turn 2:', $items[1]['label']);
        self::assertSame('2', $items[1]['value']);

        // Current leaf (indented, ◉)
        self::assertStringContainsString('└─ ◉ Turn 3:', $items[2]['label']);
        self::assertSame('3', $items[2]['value']);
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

        $controller = new TreePickerController($provider);
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

        $controller = new TreePickerController($provider);
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

        $controller = new TreePickerController($provider);
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

        $controller = new TreePickerController($provider);
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
