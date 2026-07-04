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

    // ── buildItems: linear tree ─────────────────────────────────────────────

    #[Test]
    public function testBuildItemsLinearTree(): void
    {
        $tree = $this->createLinearTree();

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        $this->assertCount(2, $items, 'Linear tree should produce 2 items');

        // Item 0: root turn (flat, leaf marker ○, title preview)
        $this->assertStringContainsString('○ Turn 1:', $items[0]['label']);
        $this->assertStringContainsString('Hello', $items[0]['label']);
        $this->assertSame('1', $items[0]['value']);
        $this->assertArrayNotHasKey('description', $items[0]);

        // Item 1: child (flat — linear only-child chain has no connectors)
        $this->assertStringContainsString('◉ Turn 2:', $items[1]['label']);
        $this->assertStringContainsString('Follow-up', $items[1]['label']);
        $this->assertSame('2', $items[1]['value']);

        // Proof: linear tree must have zero connector glyphs in every label
        foreach ($items as $item) {
            $this->assertStringNotContainsString('└─', $item['label'], 'Linear label should not contain └─');
            $this->assertStringNotContainsString('├─', $item['label'], 'Linear label should not contain ├─');
            $this->assertStringNotContainsString('│', $item['label'], 'Linear label should not contain │');
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

        $this->assertCount(3, $items);

        // First turn: leaf marker ○ (not current leaf)
        $this->assertStringContainsString('○ Turn 1:', $items[0]['label']);
        // Second turn: leaf marker ○ (not current leaf)
        $this->assertStringContainsString('○ Turn 2:', $items[1]['label']);
        // Third turn: leaf marker ◉ (current leaf)
        $this->assertStringContainsString('◉ Turn 3:', $items[2]['label']);

        // Proof: linear 3-turn tree has no connector glyphs in any label
        foreach ($items as $item) {
            $this->assertStringNotContainsString('└─', $item['label'], 'Linear label should not contain └─');
            $this->assertStringNotContainsString('├─', $item['label'], 'Linear label should not contain ├─');
            $this->assertStringNotContainsString('│', $item['label'], 'Linear label should not contain │');
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

        $this->assertCount(3, $items);

        // Root (flat, ○ — no connector)
        $this->assertStringContainsString('○ Turn 1:', $items[0]['label']);
        $this->assertSame('1', $items[0]['value']);
        $this->assertStringNotContainsString('├─', $items[0]['label']);
        $this->assertStringNotContainsString('└─', $items[0]['label']);

        // First sibling (├─ ○, not last child)
        $this->assertStringContainsString('├─ ○ Turn 2:', $items[1]['label']);
        $this->assertSame('2', $items[1]['value']);

        // Last sibling (└─ ◉, current leaf)
        $this->assertStringContainsString('└─ ◉ Turn 3:', $items[2]['label']);
        $this->assertSame('3', $items[2]['value']);
    }

    // ── buildItems: branch connectors ────────────────────────────────────────

    #[Test]
    public function testBuildItemsBranchedTreeWithConnectors(): void
    {
        // Deep branching: T1 has two children [T2, T3]; T2 has one child T4 (current leaf).
        // T4 is non-consecutive (4 ≠ 2+1) → rewind branch must fork with └─ under the open branch.
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

        $this->assertCount(4, $items);

        // T1 (root, []): flat — no branching at root level
        $this->assertStringContainsString('○ Turn 1:', $items[0]['label']);
        $this->assertStringNotContainsString('├─', $items[0]['label']);
        $this->assertStringNotContainsString('└─', $items[0]['label']);

        // T2 (first child of T1, [false]): ├─
        $this->assertStringContainsString('├─ ○ Turn 2:', $items[1]['label']);
        $this->assertSame('2', $items[1]['value']);

        // T4 (only child of T2, non-consecutive): └─ under │
        $this->assertStringContainsString('│  └─ ◉ Turn 4:', $items[2]['label']);
        $this->assertSame('4', $items[2]['value']);

        // T3 (last child of T1, [true]): └─
        $this->assertStringContainsString('└─ ○ Turn 3:', $items[3]['label']);
        $this->assertSame('3', $items[3]['value']);
    }

    // ── buildItems: linear continuation inside branch (regression) ─────────

    #[Test]
    public function testBuildItemsLinearContinuationInsideBranchDoesNotStaircase(): void
    {
        // Thesis: single-child continuations inside a branch must share the same indent
        // prefix (guide column only), not deepen with └─/├─ at every hop — the user-reported
        // staircase where 2→5→6→7 rendered one level deeper per turn.
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2, 3],
                anchorSeq: 2,
                title: 'Turn 1',
                promptPreview: 'Turn 1',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [5],
                anchorSeq: 5,
                title: 'Turn 2',
                promptPreview: 'Turn 2',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 1,
                childTurnNos: [4],
                anchorSeq: 8,
                title: 'Turn 3',
                promptPreview: 'Turn 3',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            4 => new TurnTreeNodeView(
                turnNo: 4,
                parentTurnNo: 3,
                childTurnNos: [],
                anchorSeq: 11,
                title: 'Turn 4',
                promptPreview: 'Turn 4',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            5 => new TurnTreeNodeView(
                turnNo: 5,
                parentTurnNo: 2,
                childTurnNos: [6],
                anchorSeq: 14,
                title: 'Turn 5',
                promptPreview: 'Turn 5',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            6 => new TurnTreeNodeView(
                turnNo: 6,
                parentTurnNo: 5,
                childTurnNos: [7],
                anchorSeq: 17,
                title: 'Turn 6',
                promptPreview: 'Turn 6',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            7 => new TurnTreeNodeView(
                turnNo: 7,
                parentTurnNo: 6,
                childTurnNos: [],
                anchorSeq: 20,
                title: 'Turn 7',
                promptPreview: 'Turn 7',
                createdAt: null,
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 7,
            activePathTurnNos: [1, 2, 5, 6, 7],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        $this->assertCount(7, $items);
        $this->assertStringContainsString('○ Turn 1:', $items[0]['label']);
        $this->assertStringContainsString('├─ ○ Turn 2:', $items[1]['label']);
        // Turn 5 is a rewind branch (5 ≠ 2+1) → fork glyph; 6–7 are consecutive follow-ups → flat
        $this->assertStringContainsString('│  └─ ○ Turn 5:', $items[2]['label']);
        $this->assertStringContainsString('│     ○ Turn 6:', $items[3]['label']);
        $this->assertStringContainsString('│     ◉ Turn 7:', $items[4]['label']);
        $this->assertStringContainsString('└─ ○ Turn 3:', $items[5]['label']);
        $this->assertStringContainsString('   ○ Turn 4:', $items[6]['label']);

        // Turns 6–7 are consecutive follow-ups: flat under Turn 5 (no extra fork glyphs).
        $this->assertStringNotContainsString('└─', $items[3]['label']);
        $this->assertStringNotContainsString('└─', $items[4]['label']);
        $this->assertStringNotContainsString('├─', $items[3]['label']);
        $this->assertStringNotContainsString('├─', $items[4]['label']);
    }

    #[Test]
    public function testBuildItemsRewindBranchTreeAUserOracle(): void
    {
        // Rewind branch (non-consecutive only-child) must indent with ├─/└─ so it reads as a child,
        // not a sibling; consecutive only-child follow-ups stay flat. Regression for user-reported
        // picker rendering where Turn 4 looked like Turn 2's sibling.
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2, 3],
                anchorSeq: 2,
                title: 'Hello!',
                promptPreview: 'Hello!',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [4],
                anchorSeq: 5,
                title: 'Secret word is pineapple',
                promptPreview: 'Secret word is pineapple',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 8,
                title: 'secret word is apple',
                promptPreview: 'secret word is apple',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            4 => new TurnTreeNodeView(
                turnNo: 4,
                parentTurnNo: 2,
                childTurnNos: [5],
                anchorSeq: 11,
                title: 'What is secret word',
                promptPreview: 'What is secret word',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            5 => new TurnTreeNodeView(
                turnNo: 5,
                parentTurnNo: 4,
                childTurnNos: [],
                anchorSeq: 14,
                title: 'secret word straw',
                promptPreview: 'secret word straw',
                createdAt: null,
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 5,
            activePathTurnNos: [1, 2, 4, 5],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        $this->assertCount(5, $items);
        $this->assertSame('○ Turn 1: Hello!', $items[0]['label']);
        $this->assertSame('├─ ○ Turn 2: Secret word is pineapple', $items[1]['label']);
        $this->assertSame('│  └─ ○ Turn 4: What is secret word', $items[2]['label']);
        $this->assertSame('│     ◉ Turn 5: secret word straw', $items[3]['label']);
        $this->assertSame('└─ ○ Turn 3: secret word is apple', $items[4]['label']);
    }

    #[Test]
    public function testBuildItemsRewindBranchTreeBUserOracleWithForkAtTurn2(): void
    {
        // Rewind branch (non-consecutive only-child) must indent with ├─/└─ so it reads as a child,
        // not a sibling; consecutive only-child follow-ups stay flat. Regression for user-reported
        // picker rendering where Turn 4 looked like Turn 2's sibling.
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2, 3],
                anchorSeq: 2,
                title: 'Hello!',
                promptPreview: 'Hello!',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [4, 6],
                anchorSeq: 5,
                title: 'Secret word is pineapple',
                promptPreview: 'Secret word is pineapple',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 8,
                title: 'secret word is apple',
                promptPreview: 'secret word is apple',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            4 => new TurnTreeNodeView(
                turnNo: 4,
                parentTurnNo: 2,
                childTurnNos: [5],
                anchorSeq: 11,
                title: 'What is secret word',
                promptPreview: 'What is secret word',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            5 => new TurnTreeNodeView(
                turnNo: 5,
                parentTurnNo: 4,
                childTurnNos: [],
                anchorSeq: 14,
                title: 'secret word straw',
                promptPreview: 'secret word straw',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            6 => new TurnTreeNodeView(
                turnNo: 6,
                parentTurnNo: 2,
                childTurnNos: [],
                anchorSeq: 17,
                title: 'secret word kale',
                promptPreview: 'secret word kale',
                createdAt: null,
                isCurrentLeaf: true,
            ),
        ];

        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: $nodes,
            rootTurnNos: [1],
            currentLeafTurnNo: 6,
            activePathTurnNos: [1, 2, 6],
        );

        $theme = new DefaultTheme(new ThemePalette('test'));
        $items = TreePickerController::buildItems($tree, $theme);

        $this->assertCount(6, $items);
        $this->assertSame('○ Turn 1: Hello!', $items[0]['label']);
        $this->assertSame('├─ ○ Turn 2: Secret word is pineapple', $items[1]['label']);
        $this->assertSame('│  ├─ ○ Turn 4: What is secret word', $items[2]['label']);
        $this->assertSame('│  │  ○ Turn 5: secret word straw', $items[3]['label']);
        $this->assertSame('│  └─ ◉ Turn 6: secret word kale', $items[4]['label']);
        $this->assertSame('└─ ○ Turn 3: secret word is apple', $items[5]['label']);
    }

    #[Test]
    public function testBuildItemsLinearThenForkRendersContinuationFlat(): void
    {
        // Thesis: only-child chain before a fork stays flat; fork children get ├─/└─ one level down.
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2],
                anchorSeq: 2,
                title: 'Turn 1',
                promptPreview: 'Turn 1',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [3, 4],
                anchorSeq: 5,
                title: 'Turn 2',
                promptPreview: 'Turn 2',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 2,
                childTurnNos: [],
                anchorSeq: 8,
                title: 'Turn 3',
                promptPreview: 'Turn 3',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            4 => new TurnTreeNodeView(
                turnNo: 4,
                parentTurnNo: 2,
                childTurnNos: [],
                anchorSeq: 11,
                title: 'Turn 4',
                promptPreview: 'Turn 4',
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

        $this->assertCount(4, $items);
        $this->assertStringContainsString('○ Turn 1:', $items[0]['label']);
        $this->assertStringNotContainsString('├─', $items[0]['label']);
        $this->assertStringContainsString('○ Turn 2:', $items[1]['label']);
        $this->assertStringNotContainsString('├─', $items[1]['label']);
        $this->assertStringNotContainsString('└─', $items[1]['label']);
        $this->assertStringContainsString('├─ ○ Turn 3:', $items[2]['label']);
        $this->assertStringContainsString('└─ ◉ Turn 4:', $items[3]['label']);
    }

    // ── initialSelectedIndex: open on current leaf (regression) ───────────

    #[Test]
    public function testInitialSelectedIndexCurrentLeafNotFirstReturnsDepthFirstIndex(): void
    {
        // Thesis: Tree picker must open with the cursor on the CURRENT leaf turn, not the first
        // turn, so the user's position in the conversation is preserved when navigating the tree.
        // Regression for user-reported UX bug where opening /tree always highlighted the root turn.
        // Tree 1 → {2, 3 → 4}; current leaf = turn 4 (DFS order: 1, 2, 3, 4 → index 3).
        $nodes = [
            1 => new TurnTreeNodeView(
                turnNo: 1,
                parentTurnNo: null,
                childTurnNos: [2, 3],
                anchorSeq: 2,
                title: 'Root',
                promptPreview: 'Root',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            2 => new TurnTreeNodeView(
                turnNo: 2,
                parentTurnNo: 1,
                childTurnNos: [],
                anchorSeq: 5,
                title: 'Branch A',
                promptPreview: 'A',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            3 => new TurnTreeNodeView(
                turnNo: 3,
                parentTurnNo: 1,
                childTurnNos: [4],
                anchorSeq: 8,
                title: 'Branch B',
                promptPreview: 'B',
                createdAt: null,
                isCurrentLeaf: false,
            ),
            4 => new TurnTreeNodeView(
                turnNo: 4,
                parentTurnNo: 3,
                childTurnNos: [],
                anchorSeq: 11,
                title: 'Deep leaf',
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
            activePathTurnNos: [1, 3, 4],
        );

        $this->assertSame([1, 2, 3, 4], TreePickerController::flattenTurnOrder($tree));
        $this->assertSame(3, TreePickerController::initialSelectedIndex($tree));
    }

    #[Test]
    public function testInitialSelectedIndexCurrentLeafIsRootReturnsZero(): void
    {
        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(
                    turnNo: 1,
                    parentTurnNo: null,
                    childTurnNos: [],
                    anchorSeq: 2,
                    title: 'Only turn',
                    promptPreview: 'Only',
                    createdAt: null,
                    isCurrentLeaf: true,
                ),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 1,
            activePathTurnNos: [1],
        );

        $this->assertSame(0, TreePickerController::initialSelectedIndex($tree));
    }

    #[Test]
    public function testInitialSelectedIndexMissingLeafInOrderReturnsZero(): void
    {
        $tree = new TurnTreeView(
            runId: 'run',
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(
                    turnNo: 1,
                    parentTurnNo: null,
                    childTurnNos: [2],
                    anchorSeq: 2,
                    title: 'T1',
                    promptPreview: 'T1',
                    createdAt: null,
                    isCurrentLeaf: false,
                ),
                2 => new TurnTreeNodeView(
                    turnNo: 2,
                    parentTurnNo: 1,
                    childTurnNos: [],
                    anchorSeq: 5,
                    title: 'T2',
                    promptPreview: 'T2',
                    createdAt: null,
                    isCurrentLeaf: true,
                ),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 99,
            activePathTurnNos: [1, 2],
        );

        $this->assertSame(0, TreePickerController::initialSelectedIndex($tree));
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

        $this->assertSame([], $items);
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

        $this->assertCount(1, $items);
        $this->assertLessThan(\strlen($longTitle), \strlen($items[0]['label']), 'Title should be truncated');
        $this->assertStringContainsString('…', $items[0]['label'], 'Truncation ellipsis should be present');
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

        $this->assertStringContainsString("\x1b", $items[0]['label'], 'Selected item should have ANSI accent');
        $this->assertStringNotContainsString("\x1b", $items[1]['label'], 'Unselected item should not have ANSI accent');
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

        $this->assertFalse($controller->isOpen());

        $controller->open();

        $this->assertTrue($controller->isOpen());
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

        $this->assertFalse($controller->isOpen(), 'Picker should not open for empty tree');
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
        $this->assertTrue($controller->isOpen());

        $controller->closePicker();
        $this->assertFalse($controller->isOpen());
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

        $this->assertFalse($controller->isOpen());
    }

    // ── flattenTurnOrder ────────────────────────────────────────────────────

    #[Test]
    public function testFlattenTurnOrderLinear(): void
    {
        $tree = $this->createLinearTree();
        $order = TreePickerController::flattenTurnOrder($tree);

        $this->assertSame([1, 2], $order);
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

        $this->assertSame([1, 2, 3], $order, 'Depth-first: root → branch A → branch B');
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
        $switcher->expects($this->once())
            ->method('rewindToTurn')
            ->with(1);

        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        $controller->open();
        $this->assertTrue($controller->isOpen());

        // Picker opens on current leaf (turn 2 at index 1); move to turn 1, then confirm.
        $widget = $controller->overlay()->listWidget();
        $widget->setSelectedIndex(0);
        $widget->handleInput("\r");

        $this->assertFalse($controller->isOpen(), 'Picker must close after selection');
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
        $switcher->expects($this->never())
            ->method('rewindToTurn');

        $controller = new TreePickerController($provider, $switcher);
        $controller->setRuntimeRefs($this->tui, $this->screen, $this->state);

        $controller->open();
        $this->assertTrue($controller->isOpen());

        // Move selection to turn 2 (current leaf) at index 1, then confirm.
        $widget = $controller->overlay()->listWidget();
        $widget->setSelectedIndex(1);
        $widget->handleInput("\r");

        $this->assertFalse($controller->isOpen(), 'Picker must close after selection');
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
