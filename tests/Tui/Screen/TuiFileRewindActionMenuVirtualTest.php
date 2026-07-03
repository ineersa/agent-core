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

final class TuiFileRewindActionMenuVirtualTest extends TestCase
{
    #[Test]
    public function testActionMenuShowsOnlyTwoRestoreActions(): void
    {
        $sessionId = 'rewind-actions-session';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $harness->startInputLoop();

        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn(new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [], 2, 'hello', 'hello', null, true),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 1,
            activePathTurnNos: [1],
        ));

        $previewPort = $this->createStub(FileRewindTurnPreviewPortInterface::class);
        $previewPort->method('hasCheckpoint')->willReturn(true);
        $actionPort = $this->createStub(FileRewindTurnActionPortInterface::class);

        $picker = new FileRewindPickerController($treeProvider, $previewPort, $actionPort);
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        $picker->open($sessionId);
        $harness->sendInput("\n");

        $screen = $harness->plainScreenText();
        self::assertStringContainsString('Restore files to this turn', $screen);
        self::assertStringContainsString('Restore files + conversation rewind', $screen);
        self::assertStringNotContainsString('Undo last file restore', $screen);
        self::assertStringNotContainsString('Conversation rewind only', $screen);
        self::assertStringNotContainsString('Cancel', $screen);
    }
}
