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
        self::assertSame(1, substr_count($screen, 'hello'), $screen);
        self::assertSame(1, substr_count($screen, 'Can you create file'), $screen);
        self::assertSame(1, substr_count($screen, 'Done! Created file'), $screen);
        self::assertSame(1, substr_count($screen, 'session '.$sessionId), $screen);

        $footerPos = strpos($screen, 'session '.$sessionId);
        self::assertNotFalse($footerPos);
        self::assertGreaterThan(strpos($screen, 'Done! Created file'), $footerPos, 'Footer session line must stay below picker rows');
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
        self::assertSame(1, substr_count($screen, 'checkpoint 1:'), $screen);
        self::assertSame(1, substr_count($screen, 'checkpoint 2:'), $screen);
        self::assertSame(1, substr_count($screen, 'checkpoint 3:'), $screen);
        self::assertSame(1, substr_count($screen, 'session '.$sessionId), $screen);

        $footerPos = strpos($screen, 'session '.$sessionId);
        self::assertNotFalse($footerPos);
        self::assertGreaterThan(strrpos($screen, 'checkpoint 3:'), $footerPos);
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
