<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Picker\PickerOverlayPlacementEnum;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Tui\Widget\SelectListWidget;
use Symfony\Component\Tui\Widget\TextWidget;

/**
 * Regression: tree/rewind-specific overlay fix must not move generic pickers above the editor.
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
    public function testBeforeEditorPlacementRendersAboveEditorProbeOnScreen(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'placement-before');
        $harness->screen()->promptEditor()->setText(self::EDITOR_PROBE);

        $header = new TextWidget(text: 'PICKER_HEADER_BEFORE_SLOT', truncate: true);
        $list = new SelectListWidget(items: [['value' => '1', 'label' => 'row']]);
        $overlay = new PickerOverlay();
        $overlay->mount(
            $harness->tui(),
            $harness->screen(),
            $list,
            $header,
            PickerOverlayPlacementEnum::BeforeEditor,
        );

        $screen = $harness->plainScreenText();
        $editorPos = strpos($screen, self::EDITOR_PROBE);
        $headerPos = strpos($screen, 'PICKER_HEADER_BEFORE_SLOT');

        self::assertNotFalse($editorPos, $screen);
        self::assertNotFalse($headerPos, $screen);
        self::assertLessThan($editorPos, $headerPos, 'BeforeEditor placement must appear above the editor on screen');
    }
}
