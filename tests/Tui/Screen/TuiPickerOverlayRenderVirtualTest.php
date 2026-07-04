<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Listener\TreeCommandHandler;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Picker\FileRewindPickerController;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Regression for live tmux corruption: duplicated picker rows, stacked headers,
 * and footer/session/status text bleeding into the picker overlay region.
 */
final class TuiPickerOverlayRenderVirtualTest extends TestCase
{
    #[Test]
    public function testTreePickerNavigationKeepsSingleHeaderRowsAndFooterAtBottom(): void
    {
        $sessionId = 'picker-render-tree';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $harness->startInputLoop();

        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleTree($sessionId));
        $picker = new TreePickerController($provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));

        (new TreeCommandHandler($picker))->handle(new SlashCommand('tree', '', '/tree'));

        $harness->sendInput("\x1b[A");
        $harness->sendInput("\x1b[B");
        $harness->sendInput("\x1b[B");

        $screen = $harness->plainScreenText();
        $header = 'Session turn tree — Enter to rewind (Esc to close)';

        self::assertSame(1, substr_count($screen, $header), $screen);
        self::assertSame(1, substr_count($screen, 'Turn 1: Turn 1'), $screen);
        self::assertSame(1, substr_count($screen, 'Turn 2: Turn 2'), $screen);
        self::assertSame(1, substr_count($screen, 'Turn 3: Turn 3'), $screen);
        self::assertSame(1, substr_count($screen, 'session '.$sessionId), $screen);

        $footerPos = strpos($screen, 'session '.$sessionId);
        self::assertNotFalse($footerPos);
        self::assertGreaterThan(strpos($screen, 'Turn 3: Turn 3'), $footerPos, 'Footer session line must stay below picker rows');
    }

    #[Test]
    public function testRewindPickerNavigationKeepsSingleCheckpointRowsAndFooterAtBottom(): void
    {
        $sessionId = 'picker-render-rewind';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $harness->startInputLoop();

        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn($this->sampleTree($sessionId));

        $previewPort = $this->createStub(FileRewindTurnPreviewPortInterface::class);
        $previewPort->method('hasCheckpoint')->willReturnCallback(static fn (string $sid, int $turnNo): bool => \in_array($turnNo, [1, 2, 3], true));

        $picker = new FileRewindPickerController(
            $treeProvider,
            $previewPort,
            $this->createStub(FileRewindTurnActionPortInterface::class),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        $picker->open($sessionId);

        $harness->sendInput("\x1b[A");
        $harness->sendInput("\x1b[B");

        $screen = $harness->plainScreenText();

        self::assertSame(1, substr_count($screen, 'Checkpoint turn'), $screen);
        self::assertSame(1, substr_count($screen, 'Turn 1:'), $screen);
        self::assertSame(1, substr_count($screen, 'Turn 2:'), $screen);
        self::assertSame(1, substr_count($screen, 'Turn 3:'), $screen);
        self::assertSame(1, substr_count($screen, 'session '.$sessionId), $screen);

        $footerPos = strpos($screen, 'session '.$sessionId);
        self::assertNotFalse($footerPos);
        self::assertGreaterThan(strrpos($screen, 'Turn 3:'), $footerPos);
    }

    private function sampleTree(string $sessionId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'Turn 1', 'Hey!', null, false),
                2 => new TurnTreeNodeView(2, 1, [3], 4, 'Turn 2', 'Follow up', null, false),
                3 => new TurnTreeNodeView(3, 2, [], 6, 'Turn 3', 'Third', null, true),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 3,
            activePathTurnNos: [1, 2, 3],
        );
    }
}
