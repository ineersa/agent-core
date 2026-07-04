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

final class TuiFileRewindPickerCheckpointTargetsVirtualTest extends TestCase
{
    #[Test]
    public function testRewindPickerListsOnlyCheckpointTurnsWithMeaningfulTitles(): void
    {
        $sessionId = 'rewind-targets-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);

        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn($this->sampleTree($sessionId));

        $previewPort = $this->createMock(FileRewindTurnPreviewPortInterface::class);
        $previewPort->method('hasCheckpoint')->willReturnCallback(static fn (string $sid, int $turnNo): bool => 'rewind-targets-session' === $sid && (1 === $turnNo || 3 === $turnNo));
        $previewPort->expects($this->never())->method('preview');

        $actionPort = $this->createStub(FileRewindTurnActionPortInterface::class);

        $picker = new FileRewindPickerController($treeProvider, $previewPort, $actionPort);
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        $picker->open($sessionId);

        $screen = $harness->plainScreenText();
        self::assertStringContainsString('Turn 1: hello', $screen);
        self::assertStringContainsString('Turn 3: Create test.txt', $screen);
        self::assertStringNotContainsString('Turn 2:', $screen);
        self::assertStringNotContainsString('Turn 4:', $screen);
        self::assertStringNotContainsString('Turn 5:', $screen);
        self::assertStringNotContainsString('no file checkpoint', $screen);
    }

    private function sampleTree(string $sessionId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'hello', 'hello', null, false),
                2 => new TurnTreeNodeView(2, 1, [3], 4, 'Turn 2', 'internal', null, false),
                3 => new TurnTreeNodeView(3, 2, [4], 6, 'Create test.txt', 'Create test.txt', null, false),
                4 => new TurnTreeNodeView(4, 3, [5], 8, 'Turn 4', 'internal', null, false),
                5 => new TurnTreeNodeView(5, 4, [6], 10, 'Turn 5', 'internal', null, true),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 5,
            activePathTurnNos: [1, 2, 3, 4, 5],
        );
    }
}
