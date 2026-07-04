<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Listener\TreeCommandHandler;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TuiTreePickerOverlayVirtualTest extends TestCase
{
    #[Test]
    public function testTreePickerRendersSingleHeaderAndTurnList(): void
    {
        $sessionId = 'tree-overlay-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleTree($sessionId));
        $picker = new TreePickerController($provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));

        (new TreeCommandHandler($picker))->handle(new SlashCommand('tree', '', '/tree'));

        $screen = $harness->plainScreenText();
        self::assertSame(1, substr_count($screen, 'Session turn tree — Enter to rewind (Esc to close)'));
        self::assertSame(1, substr_count($screen, 'hello'));
        self::assertSame(1, substr_count($screen, 'Can you create file'));
        self::assertSame(1, substr_count($screen, 'Done! Created file'));
    }

    #[Test]
    public function testTreePickerRemountDoesNotDuplicateRows(): void
    {
        $sessionId = 'tree-remount-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleTree($sessionId));
        $picker = new TreePickerController($provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));

        (new TreeCommandHandler($picker))->handle(new SlashCommand('tree', '', '/tree'));
        $picker->closePicker();
        $picker->open();
        $screen = $harness->plainScreenText();
        self::assertSame(1, substr_count($screen, 'Session turn tree — Enter to rewind (Esc to close)'));
        self::assertSame(1, substr_count($screen, 'hello'));
    }

    #[Test]
    public function testTreePickerLinearToolCycleShowsDistinctTurnTitles(): void
    {
        $sessionId = 'tree-tool-cycle-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn(new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'Removed test.txt', '', null, false),
                2 => new TurnTreeNodeView(2, 1, [3], 4, 'Done. test.txt removed.', '', null, false),
                3 => new TurnTreeNodeView(3, 2, [4], 6, 'Create test.txt with 1 line', '', null, false),
                4 => new TurnTreeNodeView(4, 3, [5], 8, 'Created test.txt with hello', '', null, false),
                5 => new TurnTreeNodeView(5, 4, [6], 10, 'Okay add 1 more line', '', null, false),
                6 => new TurnTreeNodeView(6, 5, [], 12, 'Added second line to test.txt', '', null, true),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 6,
            activePathTurnNos: [1, 2, 3, 4, 5, 6],
        ));
        $picker = new TreePickerController($provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        (new TreeCommandHandler($picker))->handle(new SlashCommand('tree', '', '/tree'));
        $screen = $harness->plainScreenText();
        self::assertSame(1, substr_count($screen, 'Create test.txt'));
        self::assertSame(1, substr_count($screen, 'Created test.txt'));
        self::assertSame(1, substr_count($screen, 'Okay add 1 more line'));
        self::assertSame(1, substr_count($screen, 'Added second line'));
    }

    private function sampleTree(string $sessionId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'hello', 'Hey!', null, false, 'user'),
                2 => new TurnTreeNodeView(2, 1, [3], 4, 'Can you create file', 'Follow up', null, false, 'user'),
                3 => new TurnTreeNodeView(3, 2, [], 6, 'Done! Created file', 'Third', null, true, 'assistant'),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 3,
            activePathTurnNos: [1, 2, 3],
        );
    }
}
