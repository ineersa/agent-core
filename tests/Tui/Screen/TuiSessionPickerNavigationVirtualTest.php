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
use Ineersa\Tui\Transcript\ThemeStyleSheetFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Tui\Render\Renderer;
use Symfony\Component\Tui\Tui;
use Symfony\Component\Tui\Widget\ContainerWidget;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Thesis: /resume picker navigation keeps plain labels and stylesheet accent,
 * and in-place feedback updates do not force ScreenWriter reset.
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

            $itemsProp = new \ReflectionProperty(SelectListWidget::class, 'items');
            /** @var list<array{value: string, label: string}> $items */
            $items = $itemsProp->getValue($list);
            $this->assertSame(
                SessionPickerController::buildItemsStatic([
                    ['sessionId' => $activeId, 'displayTitle' => 'Active session', 'name' => 'Active session'],
                    ['sessionId' => $secondId, 'displayTitle' => 'Second session', 'name' => 'Second session'],
                ]),
                $items,
            );

            $accentProbe = $harness->screen()->theme()->color(ThemeColorEnum::Accent, 'PROBE');
            $accentPrefix = substr($accentProbe, 0, (int) strpos($accentProbe, 'PROBE'));
            $this->assertNotSame('', $accentPrefix);

            $renderer = new Renderer();
            $renderer->addStyleSheet((new ThemeStyleSheetFactory())->createPickerSelectList($palette));
            $container = new ContainerWidget();
            $container->add($list);
            $joined = implode("\n", $renderer->render($container, 120, 20));
            $this->assertStringContainsString(
                $accentPrefix."\x1b[1m→ #".$secondId.' — Second session',
                $joined,
                'Selected session row must resolve picker-scoped Accent style',
            );
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

            $writer = $this->screenWriter($harness->tui());
            $writer->writeLines(['seed']);
            $this->assertNotSame([], $this->previousLines($writer));

            $harness->sendInput('d');
            $this->assertStringContainsString(
                \sprintf('Delete session #%s — Maybe delete?', $deleteId),
                $harness->plainScreenText(),
            );
            $this->assertNotSame(
                [],
                $this->previousLines($writer),
                'In-place confirm must not force ScreenWriter::reset()',
            );
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

    private function screenWriter(Tui $tui): object
    {
        $prop = new \ReflectionProperty(Tui::class, 'screenWriter');

        return $prop->getValue($tui);
    }

    /**
     * @return list<string>
     */
    private function previousLines(object $writer): array
    {
        $prop = new \ReflectionProperty($writer, 'previousLines');
        /** @var list<string> $lines */
        $lines = $prop->getValue($writer);

        return $lines;
    }
}
