<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Screen;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Picker\PickerOverlay;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
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
        $harness = new VirtualTuiHarness(sessionId: $activeId, columns: 140, rows: 40);
        $picker = new SessionPickerController($harness->tui(), $harness->screen(), $this->store, $switch);
        $picker->open();

        $list = $this->listWidget($picker);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();
            $this->selectSession($harness, $list, $deleteId);

            $harness->sendInput('d');
            $this->assertStringContainsString(
                \sprintf('Delete session #%s — Maybe delete?', $deleteId),
                $harness->plainScreenText(),
            );

            $harness->sendInput("\x1b"); // Esc
            $after = $harness->plainScreenText();
            $this->assertTrue($picker->isOpen());
            $this->assertTrue($this->store->exists($deleteId));
            $this->assertStringContainsString('d deletes', $after);
            $this->assertStringContainsString('#'.$deleteId.' — Maybe delete', $after);
            $this->assertStringNotContainsString('Delete session #'.$deleteId.' — Maybe delete?', $after);
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
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
