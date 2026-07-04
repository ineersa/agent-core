<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnActionPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\FileRewindTurnPreviewPortInterface;
use Ineersa\CodingAgent\Runtime\Contract\TurnTreeProviderInterface;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeNodeView;
use Ineersa\CodingAgent\Runtime\Protocol\TurnTreeView;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Listener\TreeCommandHandler;
use Ineersa\Tui\Picker\FileRewindPickerController;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Picker\TreePickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Regression: all pickers (generic, /tree, /rewind) render below the editor.
 */
final class TuiPickerOverlayPlacementVirtualTest extends TestCase
{
    private const string EDITOR_PROBE = 'EDITOR_PLACEMENT_PROBE';

    #[Test]
    public function testGenericPickerDefaultRendersBelowEditorProbeOnScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'placement-after');
        $harness->screen()->promptEditor()->setText(self::EDITOR_PROBE);

        $header = new TextWidget(text: 'PICKER_HEADER_AFTER_SLOT', truncate: true);
        $list = new SelectListWidget(items: [['value' => '1', 'label' => 'row']]);
        $overlay = new PickerOverlay();
        $overlay->mount($harness->tui(), $harness->screen(), $list, $header);

        $screen = $harness->plainScreenText();
        $editorPos = strpos($screen, self::EDITOR_PROBE);
        $headerPos = strpos($screen, 'PICKER_HEADER_AFTER_SLOT');

        self::assertNotFalse($editorPos, $screen);
        self::assertNotFalse($headerPos, $screen);
        self::assertGreaterThan($editorPos, $headerPos, 'Generic picker must appear below the editor on screen');
    }

    #[Test]
    public function testTreePickerRendersBelowEditorProbeOnScreen(): void
    {
        $sessionId = 'placement-tree';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $harness->screen()->promptEditor()->setText(self::EDITOR_PROBE);

        $provider = $this->createStub(TurnTreeProviderInterface::class);
        $provider->method('forSession')->willReturn($this->sampleTree($sessionId));
        $picker = new TreePickerController($provider, $this->createStub(TuiSessionSwitchServiceInterface::class));
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        (new TreeCommandHandler($picker))->handle(new SlashCommand('tree', '', '/tree'));

        $screen = $harness->plainScreenText();
        $editorPos = strpos($screen, self::EDITOR_PROBE);
        $headerPos = strpos($screen, 'Session turn tree');

        self::assertNotFalse($editorPos, $screen);
        self::assertNotFalse($headerPos, $screen);
        self::assertGreaterThan($editorPos, $headerPos, '/tree picker must appear below the editor');
    }

    #[Test]
    public function testRewindPickerRendersBelowEditorProbeOnScreen(): void
    {
        $sessionId = 'placement-rewind';
        $harness = new VirtualTuiHarness(sessionId: $sessionId);
        $harness->screen()->promptEditor()->setText(self::EDITOR_PROBE);

        $treeProvider = $this->createStub(TurnTreeProviderInterface::class);
        $treeProvider->method('forSession')->willReturn($this->sampleTree($sessionId));
        $previewPort = $this->createStub(FileRewindTurnPreviewPortInterface::class);
        $previewPort->method('hasCheckpoint')->willReturn(true);

        $picker = new FileRewindPickerController(
            $treeProvider,
            $previewPort,
            $this->createStub(FileRewindTurnActionPortInterface::class),
        );
        $picker->setRuntimeRefs($harness->tui(), $harness->screen(), new TuiSessionState($sessionId));
        $picker->open($sessionId);

        $screen = $harness->plainScreenText();
        $editorPos = strpos($screen, self::EDITOR_PROBE);
        $headerPos = strpos($screen, 'Checkpoint turn');

        self::assertNotFalse($editorPos, $screen);
        self::assertNotFalse($headerPos, $screen);
        self::assertGreaterThan($editorPos, $headerPos, '/rewind picker must appear below the editor');
    }

    private function sampleTree(string $sessionId): TurnTreeView
    {
        return new TurnTreeView(
            runId: $sessionId,
            nodesByTurnNo: [
                1 => new TurnTreeNodeView(1, null, [2], 2, 'hello', 'Hey!', null, false, 'user'),
                2 => new TurnTreeNodeView(2, 1, [], 5, 'Done', 'Two', null, true, 'assistant'),
            ],
            rootTurnNos: [1],
            currentLeafTurnNo: 2,
            activePathTurnNos: [1, 2],
        );
    }
}
