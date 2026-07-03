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

final class TuiFileRewindPickerNoPreviewCaptureTest extends TestCase
{
    #[Test]
    public function testPickerOpenNeverCallsPreviewPortPreview(): void
    {
        $sessionId = 'sess-no-live-preview';
        $previewPort = $this->createMock(FileRewindTurnPreviewPortInterface::class);
        $previewPort->method('hasCheckpoint')->willReturn(true);
        $previewPort->expects(self::never())->method('preview');

        $actionPort = $this->createStub(FileRewindTurnActionPortInterface::class);
        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn(new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'Turn 1', 'one', null, false),
                2 => new TurnTreeNodeView(2, 1, [], 4, 'Turn 2', 'two', null, true),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 2,
            activePathTurnNos: [1, 2],
        ));

        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $picker = new FileRewindPickerController($treeProvider, $previewPort, $actionPort);
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));

        $picker->open($sessionId);
        self::assertTrue($picker->isOpen());
        self::assertStringContainsString('file checkpoint available', $harness->plainScreenText());
        self::assertStringContainsString('select a turn, then choose restore action', $harness->plainScreenText());
    }
}
