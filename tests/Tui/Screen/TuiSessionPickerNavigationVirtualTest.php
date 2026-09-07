<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeColorEnum;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Thesis: /resume picker navigation keeps plain labels and stylesheet accent,
 * and in-place feedback updates stay differential.
 */
#[CoversClass(SessionPickerController::class)]
final class TuiSessionPickerNavigationVirtualTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $store */
        $store = self::getContainer()->get(HatfieldSessionStore::class);
        $this->store = $store;
    }

    #[Test]
    public function testArrowNavigationKeepsPlainLabelsAndStylesheetAccent(): void
    {
        $activeId = $this->store->createSession('Active session');
        $secondId = $this->store->createSession('Second session');

        $palette = new ThemePalette('picker-nav', [
            ThemeColorEnum::Accent->value => '#FF00FF',
            ThemeColorEnum::Text->value => '#FFFFFF',
            ThemeColorEnum::Muted->value => '#888888',
        ]);
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $harness = new VirtualTuiHarness(
            sessionId: $activeId,
            columns: 140,
            rows: 40,
            palette: $palette,
        );
        $picker = new SessionPickerController($harness->tui(), $harness->screen(), $this->store, $switch);
        $picker->open();

        $list = $this->listWidget($picker);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();

            $first = $list->getSelectedItem();
            $this->assertNotNull($first);
            $this->assertSame($activeId, (string) $first['value']);
            $this->assertStringNotContainsString("\x1b", (string) $first['label']);

            $harness->sendInput("\x1b[B");
            $second = $list->getSelectedItem();
            $this->assertNotNull($second);
            $this->assertSame($secondId, (string) $second['value']);
            $this->assertStringNotContainsString("\x1b", (string) $second['label']);

            $accentProbe = $harness->screen()->theme()->color(ThemeColorEnum::Accent, 'PROBE');
            $accentPrefix = substr($accentProbe, 0, (int) strpos($accentProbe, 'PROBE'));
            $this->assertNotSame('', $accentPrefix);

            $ansi = $harness->terminal()->getOutput();
            $this->assertStringContainsString(
                $accentPrefix."\x1b[1m→ #".$secondId.' — Second session',
                $ansi,
                'Selected session row must resolve picker-scoped Accent style from the attached tree',
            );
            $this->assertStringContainsString('  #'.$activeId.' — Active session', $ansi);
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function testConfirmTransitionDoesNotForceScreenWriterReset(): void
    {
        $activeId = $this->store->createSession('Keep active');
        $deleteId = $this->store->createSession('Maybe delete');

        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $harness = new VirtualTuiHarness(sessionId: $activeId, columns: 140, rows: 40);
        $picker = new SessionPickerController($harness->tui(), $harness->screen(), $this->store, $switch);
        $picker->open();

        $list = $this->listWidget($picker);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();
            $this->selectSession($harness, $list, $deleteId);

            $before = $harness->terminal()->getOutput();
            $harness->terminal()->clearOutput();
            $harness->sendInput('d');
            $delta = $harness->terminal()->getOutput();

            $this->assertStringNotContainsString(
                "\x1b[2J",
                $delta,
                'Confirm transition must stay differential and must not erase the screen',
            );
            $this->assertStringNotContainsString("\x1b[3J", $delta);

            $confirmNeedle = \sprintf('Delete session #%s — Maybe delete?', $deleteId);
            $this->assertStringContainsString($confirmNeedle, $delta);

            $buffer = new ScreenBuffer(
                width: $harness->terminal()->getColumns(),
                height: $harness->terminal()->getRows(),
            );
            $buffer->write($before);
            $buffer->write($delta);
            $this->assertStringContainsString($confirmNeedle, $buffer->getScreen());
            $this->assertStringContainsString('Yes', $buffer->getScreen());
            $this->assertStringContainsString('No', $buffer->getScreen());
        } finally {
            $harness->stopInputLoop();
        }
    }

    private function listWidget(SessionPickerController $picker): SelectListWidget
    {
        $overlayRef = new \ReflectionProperty(SessionPickerController::class, 'overlay');
        $overlay = $overlayRef->getValue($picker);
        $this->assertInstanceOf(PickerOverlay::class, $overlay);
        $list = $overlay->listWidget();
        $this->assertInstanceOf(SelectListWidget::class, $list);

        return $list;
    }

    private function selectSession(VirtualTuiHarness $harness, SelectListWidget $list, string $sessionId): void
    {
        for ($i = 0; $i < 8; ++$i) {
            $selected = $list->getSelectedItem();
            if (null !== $selected && (string) $selected['value'] === $sessionId) {
                return;
            }

            $harness->sendInput("\x1b[B");
        }

        $this->fail('Failed to highlight session '.$sessionId);
    }
}
