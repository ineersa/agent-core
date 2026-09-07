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
use Ineersa\Tui\Transcript\TranscriptBlockFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Tui\Terminal\ScreenBuffer;
use Symfony\Component\Tui\Widget\SelectListWidget;

/**
 * Thesis: /resume picker delete requires Yes/No confirm, refuses the active
 * session, and physically removes a confirmed non-active session (virtual layer).
 */
#[CoversClass(SessionPickerController::class)]
final class TuiSessionPickerDeleteVirtualTest extends IsolatedKernelTestCase
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
    public function testConfirmEscCancelsWithoutDeleting(): void
    {
        $activeId = $this->store->createSession('Keep active');
        $deleteId = $this->store->createSession('Maybe delete');

        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $harness = new VirtualTuiHarness(sessionId: $activeId, columns: 140, rows: 40, palette: new ThemePalette('test', [ThemeColorEnum::Accent->value => 'magenta']));
        $harness->screen()->setTranscriptBlocks([(new TranscriptBlockFactory())->system(runId: $activeId, text: 'Retained session transcript', seq: 1)]);
        $picker = new SessionPickerController($harness->tui(), $harness->screen(), $this->store, $switch);
        $picker->open();

        $list = $this->listWidget($picker);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();
            $this->selectSession($harness, $list, $deleteId);
            $buffer = new ScreenBuffer(width: 140, height: 40);
            $buffer->write($harness->terminal()->consumeOutput());
            $this->assertSelectedAccent($buffer, $deleteId);
            $harness->sendInput("\x1b[B");
            $buffer->write($harness->terminal()->consumeOutput());
            $this->assertSelectedAccent($buffer, $activeId);
            $harness->sendInput("\x1b[A");
            $buffer->write($harness->terminal()->consumeOutput());
            $this->assertSelectedAccent($buffer, $deleteId);

            $harness->sendInput('d');
            $delta = $harness->terminal()->consumeOutput();
            $this->assertStringNotContainsString('Retained session transcript', $delta);
            $this->assertStringNotContainsString("\x1b[2J", $delta);
            $buffer->write($delta);
            $this->assertStringContainsString(
                \sprintf('Delete session #%s — Maybe delete?', $deleteId),
                $buffer->getScreen(),
            );

            $harness->sendInput("\x1b"); // Esc
            $delta = $harness->terminal()->consumeOutput();
            $this->assertStringNotContainsString('Retained session transcript', $delta);
            $this->assertStringNotContainsString("\x1b[2J", $delta);
            $buffer->write($delta);
            $after = $buffer->getScreen();
            $overlayRef = new \ReflectionProperty(SessionPickerController::class, 'overlay');
            $overlay = $overlayRef->getValue($picker);
            $this->assertInstanceOf(PickerOverlay::class, $overlay);
            $this->assertTrue($overlay->isOpen());
            $this->assertTrue($this->store->exists($deleteId));
            $this->assertStringContainsString('d deletes', $after);
            $this->assertStringContainsString('#'.$deleteId.' — Maybe delete', $after);
            $this->assertStringNotContainsString('Delete session #'.$deleteId.' — Maybe delete?', $after);
            $this->assertStringContainsString('Retained session transcript', $after);

            // Rename reuses the native list and returns focus without a reset.
            $picker->closePicker();
            $picker->openForRenameCommand();
            $list = $this->listWidget($picker);
            $selectedId = $list->getSelectedItem()['value'];
            $harness->tui()->processRender();
            $harness->terminal()->consumeOutput();
            $harness->sendInput("\r");
            $delta = $harness->terminal()->consumeOutput();
            $this->assertStringNotContainsString('Retained session transcript', $delta);
            $this->assertStringNotContainsString("\x1b[2J", $delta);
            $this->assertSame('/rename '.$selectedId.' ', $harness->screen()->promptEditor()->getText());
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

    private function assertSelectedAccent(ScreenBuffer $buffer, string $sessionId): void
    {
        preg_match_all('/^.*→.*$/m', $buffer->getStyledScreen(), $matches);
        $this->assertCount(1, $matches[0]);
        $this->assertStringContainsString($sessionId, $matches[0][0]);
        $this->assertMatchesRegularExpression('/\x1b\[[0-9;]*35[0-9;]*m/', $matches[0][0]);
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
