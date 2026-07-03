<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Picker\FileRewindPickerController;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TuiFileRewindPickerOverlayNavigationVirtualTest extends TestCase
{
    #[Test]
    public function testRewindPickerNavigationDoesNotStackOverlayHeader(): void
    {
        $sessionId = 'rewind-nav-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $harness->startInputLoop();

        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn($this->sampleTree($sessionId));

        $previewPort = $this->createMock(FileRewindTurnPreviewPortInterface::class);
        $previewPort->method('hasCheckpoint')->willReturnCallback(static fn (string $sid, int $turnNo): bool => 2 === $turnNo || 4 === $turnNo);
        $previewPort->expects($this->never())->method('preview');

        $actionPort = $this->createStub(FileRewindTurnActionPortInterface::class);

        $picker = new FileRewindPickerController($treeProvider, $previewPort, $actionPort);
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        $picker->open($sessionId);

        $header = 'File rewind — select turn (Esc to close)';
        self::assertSame(1, substr_count($harness->plainScreenText(), $header));

        $harness->sendInput("\x1b[A"); // Up
        $harness->sendInput("\x1b[A"); // Up
        $harness->sendInput("\x1b[B"); // Down

        self::assertSame(1, substr_count($harness->plainScreenText(), $header));
    }

    private function sampleTree(string $sessionId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'Turn 1', 'First', null, false),
                2 => new TurnTreeNodeView(2, 1, [3], 4, 'Turn 2', 'Second', null, false),
                3 => new TurnTreeNodeView(3, 2, [4], 6, 'Turn 3', 'Third', null, false),
                4 => new TurnTreeNodeView(4, 3, [5], 8, 'Turn 4', 'Fourth', null, false),
                5 => new TurnTreeNodeView(5, 4, [6], 10, 'Turn 5', 'Fifth', null, false),
                6 => new TurnTreeNodeView(6, 5, [], 12, 'Turn 6', 'Sixth', null, true),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 6,
            activePathTurnNos: [1, 2, 3, 4, 5, 6],
        );
    }
}
