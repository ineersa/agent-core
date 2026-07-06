<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Domain\Run;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Run\TurnTreeProjector;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TurnTreeProjector::class)]
final class TurnTreeProjectorTest extends TestCase
{
    private TurnTreeProjector $projector;
    private string $runId = 'run-tree-test';

    protected function setUp(): void
    {
        $this->projector = new TurnTreeProjector();
    }

    // ── Empty stream ─────────────────────────────────────────────────────────

    public function testEmptyStreamReturnsEmptyTree(): void
    {
        $tree = $this->projector->build($this->runId, []);

        $this->assertSame($this->runId, $tree->runId);
        $this->assertSame([], $tree->nodesByTurnNo);
        $this->assertSame([], $tree->rootTurnNos);
        $this->assertNull($tree->currentLeafTurnNo);
        $this->assertSame([], $tree->activePathTurnNos);
    }

    // ── Old-style linear stream (no leaf_set, no parent_turn_no) ─────────────

    public function testOldStyleLinearStream(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                ]],
            ]),
            $this->turnAdvancedEvent(2, 1, null), // Turn 1: no parent_turn_no key
            $this->runEvent('llm_step_completed', 3, 1, [
                'text' => 'Hi! How can I help?',
            ]),
            $this->runEvent('agent_command_applied', 4, 1, [
                'kind' => 'follow_up',
                'text' => 'Write a test.',
            ]),
            $this->turnAdvancedEvent(5, 2, null), // Turn 2: no parent_turn_no key
            $this->runEvent('llm_step_completed', 6, 2, [
                'text' => 'Here is a test...',
            ]),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertCount(2, $tree->nodesByTurnNo);
        $this->assertSame([1], $tree->rootTurnNos);
        $this->assertSame(2, $tree->currentLeafTurnNo);
        $this->assertSame([1, 2], $tree->activePathTurnNos);

        // Turn 1 is root, parent derived as null
        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertNull($turn1->parentTurnNo);
        $this->assertSame([2], $turn1->childTurnNos);
        $this->assertSame(2, $turn1->anchorSeq);
        $this->assertFalse($turn1->isCurrentLeaf);
        $this->assertStringContainsString('Hello', $turn1->title, 'Turn 1 title comes from initial user message');

        // Turn 2 parent derived as turn 1
        $turn2 = $tree->nodesByTurnNo[2];
        $this->assertSame(1, $turn2->parentTurnNo);
        $this->assertSame([], $turn2->childTurnNos);
        $this->assertSame(5, $turn2->anchorSeq);
        $this->assertTrue($turn2->isCurrentLeaf);
        $this->assertStringContainsString('Write a test', $turn2->title, 'Turn 2 title comes from follow-up user message');
    }

    // ── New-style linear stream (explicit parent_turn_no + leaf_set) ─────────

    public function testNewStyleLinearStream(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hello']]],
                ]],
            ]),
            // Turn 1: parent_turn_no = null (root)
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, [
                'text' => 'Hi! How can I help?',
            ]),
            $this->runEvent('agent_command_applied', 5, 1, [
                'kind' => 'steer',
                'text' => 'Write a test.',
            ]),
            // Turn 2: parent_turn_no = 1
            $this->turnAdvancedEvent(6, 2, 1),
            $this->leafSetEvent(7, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 8, 2, [
                'text' => 'Here is a test...',
            ]),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertCount(2, $tree->nodesByTurnNo);
        $this->assertSame([1], $tree->rootTurnNos);
        $this->assertSame(2, $tree->currentLeafTurnNo, 'Last leaf_set points to turn 2');
        $this->assertSame([1, 2], $tree->activePathTurnNos);

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertNull($turn1->parentTurnNo);
        $this->assertSame(2, $turn1->anchorSeq);
        $this->assertFalse($turn1->isCurrentLeaf);

        $turn2 = $tree->nodesByTurnNo[2];
        $this->assertSame(1, $turn2->parentTurnNo);
        $this->assertSame(6, $turn2->anchorSeq);
        $this->assertTrue($turn2->isCurrentLeaf);
    }

    // ── Branch from earlier turn ─────────────────────────────────────────────

    public function testBranchFromEarlierTurn(): void
    {
        // Turn 1: initial
        // Turn 2: continues from turn 1 with some content
        // Leaf rolls back to turn 1
        // Turn 3: branches from turn 1 (new branch)
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Answer A']),
            // Turn 2: branch from turn 1
            $this->turnAdvancedEvent(5, 2, 1),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Answer B - abandoned']),
            // Rewind: leaf_set back to turn 1
            $this->runEvent('agent_command_applied', 8, 2, ['kind' => 'steer', 'text' => 'Try again']),
            $this->leafSetEvent(9, 1, 2, null, 'rewind'),
            // Turn 3: new branch from turn 1
            $this->turnAdvancedEvent(10, 3, 1),
            $this->leafSetEvent(11, 3, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 12, 3, ['text' => 'Answer C - active']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertCount(3, $tree->nodesByTurnNo);
        $this->assertSame([1], $tree->rootTurnNos);
        $this->assertSame(3, $tree->currentLeafTurnNo, 'Last leaf_set points to turn 3');
        $this->assertSame([1, 3], $tree->activePathTurnNos, 'Active path is 1→3, turn 2 is abandoned');

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertNull($turn1->parentTurnNo);
        $this->assertSame([2, 3], $turn1->childTurnNos, 'Turn 1 has two children: 2 and 3');
        $this->assertFalse($turn1->isCurrentLeaf);

        $turn2 = $tree->nodesByTurnNo[2];
        $this->assertSame(1, $turn2->parentTurnNo);
        $this->assertFalse($turn2->isCurrentLeaf);

        $turn3 = $tree->nodesByTurnNo[3];
        $this->assertSame(1, $turn3->parentTurnNo);
        $this->assertTrue($turn3->isCurrentLeaf);
    }

    // ── Multiple sequential branches ─────────────────────────────────────────

    public function testMultipleSequentialBranches(): void
    {
        // Complex tree: turn 1 → turn 2, turn 1 → turn 3, then switch back to turn 2 → turn 4
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            // Turn 2 from turn 1
            $this->turnAdvancedEvent(4, 2, 1),
            $this->leafSetEvent(5, 2, 1, 1, 'continue'),
            // Rewind to turn 1
            $this->leafSetEvent(6, 1, 2, null, 'rewind'),
            // Turn 3 from turn 1
            $this->turnAdvancedEvent(7, 3, 1),
            $this->leafSetEvent(8, 3, 1, 1, 'continue'),
            // Switch to turn 2
            $this->leafSetEvent(9, 2, 3, 1, 'rewind'),
            // Turn 4 from turn 2
            $this->turnAdvancedEvent(10, 4, 2),
            $this->leafSetEvent(11, 4, 2, 2, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertCount(4, $tree->nodesByTurnNo);
        $this->assertSame(4, $tree->currentLeafTurnNo);
        $this->assertSame([1, 2, 4], $tree->activePathTurnNos, 'Active path: 1→2→4');

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertSame([2, 3], $turn1->childTurnNos);

        $turn2 = $tree->nodesByTurnNo[2];
        $this->assertSame([4], $turn2->childTurnNos);
        $this->assertFalse($turn2->isCurrentLeaf);

        $turn4 = $tree->nodesByTurnNo[4];
        $this->assertSame(2, $turn4->parentTurnNo);
        $this->assertTrue($turn4->isCurrentLeaf);
    }

    // ── Title generation ─────────────────────────────────────────────────────

    public function testTitleFromInitialUserMessage(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Write a README']]],
                ]],
            ]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertStringContainsString('Write a README', $tree->nodesByTurnNo[1]->title);
    }

    public function testTitleFromFollowUpMessage(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('agent_command_applied', 4, 1, [
                'kind' => 'follow_up',
                'text' => 'Add unit tests please.',
            ]),
            $this->turnAdvancedEvent(5, 2, 1),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertStringContainsString('Add unit tests please', $tree->nodesByTurnNo[2]->title);
    }

    public function testTitleFallbackToTurnN(): void
    {
        $events = [
            $this->turnAdvancedEvent(1, 1, null),
            $this->leafSetEvent(2, 1, null, null, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        self::assertSame('Assistant response (turn 1)', $tree->nodesByTurnNo[1]->title);
    }

    // ── Node metadata ────────────────────────────────────────────────────────

    public function testAnchorSeqAndCreatedAtPreserved(): void
    {
        $createdAt = new \DateTimeImmutable('2026-06-01T12:00:00+00:00');

        $events = [
            new RunEvent(
                runId: $this->runId,
                seq: 1,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: ['turn_no' => 1, 'parent_turn_no' => null],
                createdAt: $createdAt,
            ),
            $this->leafSetEvent(2, 1, null, null, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $turn1 = $tree->nodesByTurnNo[1];
        $this->assertNull($turn1->parentTurnNo, 'Explicit parent_turn_no => null should yield null parent');
        $this->assertSame(1, $turn1->anchorSeq);
        $this->assertNotNull($turn1->createdAt);
        $this->assertSame($createdAt->getTimestamp(), $turn1->createdAt->getTimestamp());
    }

    // ── Last sequence projection ─────────────────────────────────────────────

    public function testLastSeqInLinearNewStyleStream(): void
    {
        // Stream: turn1 has seq 2-5, turn2 (current leaf) has seq 6-8.
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Answer A']),
            $this->runEvent('agent_command_applied', 5, 1, ['kind' => 'steer', 'text' => 'Do more']),
            $this->turnAdvancedEvent(6, 2, 1),
            $this->leafSetEvent(7, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 8, 2, ['text' => 'Answer B']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        // Turn 1 is not the current leaf. Its last event is seq 5 (agent_command_applied).
        $this->assertSame(5, $tree->nodesByTurnNo[1]->lastSeq, 'Turn 1 lastSeq covers its own scoped events only');

        // Turn 2 is the current leaf. Its anchor is seq 6, max scoped seq is 8.
        $this->assertSame(8, $tree->nodesByTurnNo[2]->lastSeq, 'Turn 2 lastSeq covers its own scoped events up to max');
    }

    public function testLastSeqRewindOnlyNoNewBranch(): void
    {
        // Turn 1 → Turn 2, then leaf_set rewinds to Turn 1 with no new branch yet.
        // Turn 1 should claim the rewind leaf_set seq (canonical max).
        // Abandoned Turn 2 should remain capped at its own last event.
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Turn 1 answer']),
            // Turn 2 from turn 1
            $this->turnAdvancedEvent(5, 2, 1),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Turn 2 answer (abandoned)']),
            $this->runEvent('agent_command_applied', 8, 2, ['kind' => 'steer', 'text' => 'Try again']),
            // Rewind: leaf_set back to turn 1, no new turn_advanced follows
            $this->leafSetEvent(9, 1, 2, 2, 'rewind'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertCount(2, $tree->nodesByTurnNo);
        $this->assertSame(1, $tree->currentLeafTurnNo, 'Leaf should be back at turn 1 after rewind');
        $this->assertSame([1], $tree->activePathTurnNos);

        // Turn 1 is now the current leaf. The rewind leaf_set at seq 9 is scoped to turn 1,
        // so turn 1 lastSeq should include it, not stop at the next anchor (seq 5).
        $this->assertSame(9, $tree->nodesByTurnNo[1]->lastSeq, 'Rewound turn 1 lastSeq includes rewind leaf_set');

        // Turn 2 is abandoned. Its last event is seq 8 (agent_command_applied).
        // It must NOT claim the canonical max (seq 9) because leaf_set belongs to turn 1.
        $this->assertSame(8, $tree->nodesByTurnNo[2]->lastSeq, 'Abandoned turn 2 lastSeq is capped at its own events');
    }

    public function testLastSeqAbandonedTurnDoesNotClaimActiveBranchMax(): void
    {
        // Turn 1 → Turn 2 (abandoned) → rewind to Turn 1 → Turn 3 (active).
        // The abandoned Turn 2 must NOT claim the later active branch's max seq.
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Answer from turn 1']),
            // Turn 2 (branch, will be abandoned)
            $this->turnAdvancedEvent(5, 2, 1),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Answer from turn 2 (abandoned)']),
            // Rewind to turn 1
            $this->leafSetEvent(8, 1, 2, 2, 'rewind'),
            // Turn 3 (new branch from turn 1, active)
            $this->turnAdvancedEvent(9, 3, 1),
            $this->leafSetEvent(10, 3, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 11, 3, ['text' => 'Answer from turn 3 (active)']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        $this->assertSame(3, $tree->currentLeafTurnNo, 'Current leaf is turn 3');
        $this->assertSame([1, 3], $tree->activePathTurnNos);

        // Turn 2 is abandoned. Its own last event is seq 7 (llm_step_completed).
        // It must NOT capture rewind leaf_set (seq 8, scoped to turn 1) or
        // active branch events (seq 9+, scoped to turn 3).
        $this->assertSame(7, $tree->nodesByTurnNo[2]->lastSeq, 'Abandoned turn 2 must not claim active branch max seq');

        // Turn 1's lastSeq includes the rewind leaf_set (seq 8) scoped to turn 1,
        // not capped by turn 3's later anchor.
        $this->assertSame(8, $tree->nodesByTurnNo[1]->lastSeq, 'Turn 1 lastSeq includes rewind leaf_set before turn 3');

        // Turn 3 (current leaf) includes its own max scoped seq (11).
        $this->assertSame(11, $tree->nodesByTurnNo[3]->lastSeq, 'Turn 3 lastSeq includes its own scoped max');
    }

    public function testPromptPreviewTruncationUsesUnicodeEllipsis(): void
    {
        $longText = str_repeat('A very long title that exceeds the prompt preview limit of sixty characters. ', 2);
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => $longText]]],
                ]],
            ]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);
        $turn1 = $tree->nodesByTurnNo[1];

        $title = $turn1->title;
        $preview = $turn1->promptPreview;

        // Title is truncated at 80 chars; promptPreview at 60 chars.
        // Both must use the same Unicode ellipsis (…) from the truncate() helper,
        // never ASCII "...".
        $this->assertStringEndsWith('…', $title, 'Title should end with Unicode ellipsis when truncated');
        $this->assertStringEndsWith('…', $preview, 'promptPreview should end with Unicode ellipsis, not ASCII dots');
        $this->assertLessThan(\strlen($title), \strlen($preview), 'promptPreview should be shorter than title');
        $this->assertLessThanOrEqual(60, mb_strlen($preview), 'promptPreview should be at most 60 characters');
    }

    // ── Cycle detection ──────────────────────────────────────────────────────

    public function testCycleDetectionThrowsException(): void
    {
        // Turn 2 parent is 3, Turn 3 parent is 2 → cycle
        $events = [
            $this->turnAdvancedEvent(1, 1, null),
            $this->leafSetEvent(2, 1, null, null, 'continue'),
            $this->turnAdvancedEvent(3, 2, 3), // parent turn 3 (doesn't exist yet)
            $this->turnAdvancedEvent(4, 3, 2), // parent turn 2 → cycle
            $this->leafSetEvent(5, 3, null, null, 'continue'),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cycle detected');

        $this->projector->build($this->runId, $events);
    }

    public function testEventBackedRootTurnIncludedInActivePath(): void
    {
        // Turn 1 has canonical events (user/assistant messages) but no
        // turn_advanced anchor. Turn 3 references turn 1 as its parent.
        // walkActivePath must include turn 1 in the active path as a valid
        // terminal root with canonical stream events.
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->runEvent('assistant_message', 2, 1, ['message' => [
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Hello from turn 1']],
            ]]),
            $this->turnAdvancedEvent(3, 2, 1),
            $this->leafSetEvent(4, 2, 1, 1, 'continue'),
            $this->turnAdvancedEvent(5, 3, 1),
            $this->leafSetEvent(6, 3, 2, 1, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        // leaf should be turn 3
        $this->assertSame(3, $tree->currentLeafTurnNo);
        // active path must include event-backed root turn 1, but NOT
        // the abandoned sibling turn 2 (leaf_set rewinded before
        // turn 3 was created).
        $this->assertSame([1, 3], $tree->activePathTurnNos);

        // Turns 2 and 3 both have turn 1 as parent
        $this->assertSame(1, $tree->nodesByTurnNo[2]->parentTurnNo);
        $this->assertSame(1, $tree->nodesByTurnNo[3]->parentTurnNo);
        // Turn 1 itself has no node (no turn_advanced anchor)
        $this->assertFalse(isset($tree->nodesByTurnNo[1]), 'Turn 1 has no turn_advanced anchor, so no node');
    }

    public function testDanglingParentTurnNoThrowsException(): void
    {
        // Turn 2 has parent_turn_no = 99, which does not exist in turnInfo.
        // leaf_set points to turn 2 as the current leaf.
        // walkActivePath should throw when it encounters the dangling reference.
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->turnAdvancedEvent(4, 2, 99), // parent 99 — non-existent
            $this->leafSetEvent(5, 2, 1, 99, 'continue'),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Dangling parent_turn_no 99');

        $this->projector->build($this->runId, $events);
    }


    public function testDisplayRoleUserForFollowUpAndInitialTurn(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[overlay-verify] create test.txt']]],
                ]],
            ]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Created file']),
            $this->runEvent('agent_command_applied', 5, 1, [
                'kind' => 'follow_up',
                'text' => '[overlay-verify] append LINE_TWO',
            ]),
            $this->turnAdvancedEvent(6, 2, 1, 'follow_up-2'),
            $this->leafSetEvent(7, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 8, 2, ['text' => 'Done. two lines.']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        self::assertSame('user', $tree->nodesByTurnNo[1]->displayRole);
        self::assertSame('user', $tree->nodesByTurnNo[2]->displayRole);
        self::assertStringContainsString('create test.txt', $tree->nodesByTurnNo[1]->title);
        self::assertStringContainsString('append LINE_TWO', $tree->nodesByTurnNo[2]->title);
    }

    public function testDisplayRoleUserWhenSteerAppliedInParentTurnBeforeAdvanceAfterToolsAnchor(): void
    {
        // Mirrors live PHAR smoke events.jsonl: follow-up steer is stamped on turn 1
        // (seq 12) but turn 2 opens via advance-after-tools; title window includes the steer.
        $events = [
            $this->runEvent('run_started', 1, 0, [
                'payload' => ['messages' => [
                    ['role' => 'user', 'content' => [['type' => 'text', 'text' => '[overlay-verify] create test.txt']]],
                ]],
            ]),
            $this->turnAdvancedEvent(2, 1, null, 'start-follow-up-1'),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 5, 1, ['text' => '']),
            $this->runEvent('tool_batch_committed', 11, 1, []),
            $this->runEvent('agent_command_applied', 12, 1, [
                'kind' => 'steer',
                'text' => '[overlay-verify] append LINE_TWO as second line to test.txt',
            ]),
            $this->turnAdvancedEvent(13, 2, 1, 'advance-after-tools-2'),
            $this->leafSetEvent(14, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 15, 2, ['text' => '']),
            $this->runEvent('tool_batch_committed', 21, 2, []),
            $this->turnAdvancedEvent(22, 3, 2, 'advance-after-tools-3'),
            $this->leafSetEvent(23, 3, 2, 2, 'continue'),
            $this->runEvent('llm_step_completed', 24, 3, ['text' => 'Done. test.txt now contains two lines.']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        self::assertSame('user', $tree->nodesByTurnNo[1]->displayRole);
        self::assertSame('user', $tree->nodesByTurnNo[2]->displayRole);
        self::assertSame('assistant', $tree->nodesByTurnNo[3]->displayRole);
        self::assertStringContainsString('append LINE_TWO', $tree->nodesByTurnNo[2]->title);
        self::assertStringContainsString('Done.', $tree->nodesByTurnNo[3]->title);
    }

    public function testPlaceholderTitleUsesRoleNotRawTurnN(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null, 'advance-after-tools-1'),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
        ];

        $tree = $this->projector->build($this->runId, $events);

        self::assertSame('assistant', $tree->nodesByTurnNo[1]->displayRole);
        self::assertStringContainsString('Assistant response', $tree->nodesByTurnNo[1]->title);
        self::assertStringNotContainsString('assistant: Turn', $tree->nodesByTurnNo[1]->title);
        self::assertDoesNotMatchRegularExpression('/^Turn \d+$/', $tree->nodesByTurnNo[1]->title);
    }

    public function testToolCycleTurnsDoNotReusePreviousUserPromptAsTitle(): void
    {
        $events = [
            $this->runEvent('run_started', 1, 0, ['payload' => ['messages' => []]]),
            $this->turnAdvancedEvent(2, 1, null),
            $this->leafSetEvent(3, 1, null, null, 'continue'),
            $this->runEvent('llm_step_completed', 4, 1, ['text' => 'Removed test.txt']),
            $this->turnAdvancedEvent(5, 2, 1, 'advance-after-tools-1'),
            $this->leafSetEvent(6, 2, 1, 1, 'continue'),
            $this->runEvent('llm_step_completed', 7, 2, ['text' => 'Done. test.txt removed.']),
            $this->runEvent('agent_command_applied', 8, 2, [
                'kind' => 'follow_up',
                'text' => 'Create test.txt with 1 line',
            ]),
            $this->turnAdvancedEvent(9, 3, 2, 'follow_up-3'),
            $this->leafSetEvent(10, 3, 2, 2, 'continue'),
            $this->turnAdvancedEvent(11, 4, 3, 'advance-after-tools-4'),
            $this->leafSetEvent(12, 4, 3, 3, 'continue'),
            $this->runEvent('llm_step_completed', 13, 4, ['text' => 'Created test.txt with hello']),
            $this->runEvent('agent_command_applied', 14, 4, [
                'kind' => 'follow_up',
                'text' => 'Okay add 1 more line',
            ]),
            $this->turnAdvancedEvent(15, 5, 4, 'follow_up-5'),
            $this->leafSetEvent(16, 5, 4, 4, 'continue'),
            $this->turnAdvancedEvent(17, 6, 5, 'advance-after-tools-6'),
            $this->leafSetEvent(18, 6, 5, 5, 'continue'),
            $this->runEvent('llm_step_completed', 19, 6, ['text' => 'Added second line to test.txt']),
        ];

        $tree = $this->projector->build($this->runId, $events);

        self::assertCount(6, $tree->nodesByTurnNo);
        self::assertStringContainsString('Create test.txt', $tree->nodesByTurnNo[3]->title);
        self::assertStringContainsString('Created test.txt', $tree->nodesByTurnNo[4]->title);
        self::assertNotSame($tree->nodesByTurnNo[3]->title, $tree->nodesByTurnNo[4]->title);
        self::assertStringContainsString('Okay add 1 more line', $tree->nodesByTurnNo[5]->title);
        self::assertStringContainsString('Added second line', $tree->nodesByTurnNo[6]->title);
        self::assertNotSame($tree->nodesByTurnNo[5]->title, $tree->nodesByTurnNo[6]->title);
        self::assertSame('user', $tree->nodesByTurnNo[3]->displayRole);
        self::assertSame('assistant', $tree->nodesByTurnNo[4]->displayRole);
        self::assertSame('user', $tree->nodesByTurnNo[5]->displayRole);
        self::assertSame('assistant', $tree->nodesByTurnNo[6]->displayRole);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $payload
     */
    private function runEvent(string $type, int $seq, int $turnNo, array $payload = []): RunEvent
    {
        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: $type,
            payload: $payload,
        );
    }

    /**
     * Create a turn_advanced event with optional parent_turn_no in payload.
     */
    private function turnAdvancedEvent(int $seq, int $turnNo, ?int $parentTurnNo, ?string $stepId = null): RunEvent
    {
        $payload = [
            'turn_no' => $turnNo,
            'step_id' => $stepId ?? ('step-' . $turnNo),
        ];

        // Include parent_turn_no key for new-style streams when explicit.
        // For old-style streams (tests that pass null), omit the key.
        if (null !== $parentTurnNo) {
            $payload['parent_turn_no'] = $parentTurnNo;
        }

        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: $payload,
        );
    }

    /**
     * Create a leaf_set event.
     */
    private function leafSetEvent(int $seq, int $turnNo, ?int $previousTurnNo, ?int $parentTurnNo, string $reason): RunEvent
    {
        $payload = [
            'turn_no' => $turnNo,
            'reason' => $reason,
        ];

        if (null !== $previousTurnNo) {
            $payload['previous_turn_no'] = $previousTurnNo;
        }
        if (null !== $parentTurnNo) {
            $payload['parent_turn_no'] = $parentTurnNo;
        }

        return new RunEvent(
            runId: $this->runId,
            seq: $seq,
            turnNo: $turnNo,
            type: RunEventTypeEnum::LeafSet->value,
            payload: $payload,
        );
    }
}
