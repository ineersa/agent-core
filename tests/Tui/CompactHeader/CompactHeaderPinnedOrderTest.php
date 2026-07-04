<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\CompactHeader;

use Ineersa\Tui\CompactHeader\CompactHeaderSnapshot;
use Ineersa\Tui\CompactHeader\CompactHeaderWidget;
use Ineersa\Tui\Layout\TuiSlotRegistry;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Widget\TuiRenderContext;
use Ineersa\Tui\Widget\TuiWidget;
use Ineersa\Tui\Widget\WidgetPlacementEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompactHeaderPinnedOrderTest extends TestCase
{
    #[Test]
    public function compactHeaderRendersAfterDefaultOrderWidgetsInMerge(): void
    {
        $harness = new VirtualTuiHarness(columns: 100, rows: 30);
        $screen = $harness->screen();

        $other = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['OTHER-WIDGET-LINE'];
            }
        };

        $compact = new CompactHeaderWidget();
        $compact->setSnapshot(new CompactHeaderSnapshot(skills: ['pinned-skill']));

        $screen->extensionContext()->setWidget('other', $other, WidgetPlacementEnum::AboveEditor, 0);
        $screen->extensionContext()->setWidget('compact-header', $compact, WidgetPlacementEnum::AboveEditor, TuiSlotRegistry::ORDER_PINNED_LAST);
        $screen->refresh();
        $harness->render();

        $plain = $harness->plainScreenText();
        $otherPos = strpos($plain, 'OTHER-WIDGET-LINE');
        $skillPos = strpos($plain, 'pinned-skill');

        self::assertNotFalse($otherPos);
        self::assertNotFalse($skillPos);
        self::assertLessThan($skillPos, $otherPos, 'Default-order widget must appear above compact-header in the merge');
    }

    #[Test]
    public function additionalDefaultOrderWidgetsDoNotDisplacePinnedCompactHeader(): void
    {
        $harness = new VirtualTuiHarness(columns: 100, rows: 30);
        $screen = $harness->screen();

        $compact = new CompactHeaderWidget();
        $compact->setSnapshot(new CompactHeaderSnapshot(prompts: ['z-last']));

        $screen->extensionContext()->setWidget('compact-header', $compact, WidgetPlacementEnum::AboveEditor, TuiSlotRegistry::ORDER_PINNED_LAST);

        $late = new class implements TuiWidget {
            public function render(TuiRenderContext $context): array
            {
                return ['LATE-OTHER'];
            }
        };
        $screen->extensionContext()->setWidget('late', $late, WidgetPlacementEnum::AboveEditor, 0);
        $screen->refresh();
        $harness->render();

        $plain = $harness->plainScreenText();
        $latePos = strpos($plain, 'LATE-OTHER');
        $promptPos = strpos($plain, '/z-last');

        self::assertNotFalse($latePos);
        self::assertNotFalse($promptPos);
        self::assertLessThan($promptPos, $latePos);
    }
}
