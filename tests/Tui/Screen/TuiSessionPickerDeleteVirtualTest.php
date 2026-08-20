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
    public function testDeleteRequiresConfirmAndRemovesNonActiveSession(): void
    {
        $activeId = $this->store->createSession('Active keep');
        $deleteId = $this->store->createSession('Delete target');

        $switch = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switch->expects($this->never())->method('requestResume');

        $harness = new VirtualTuiHarness(sessionId: $activeId, columns: 140, rows: 40);
        $picker = new SessionPickerController($harness->tui(), $harness->screen(), $this->store, $switch);
        $picker->open();
        $this->assertTrue($picker->isOpen());

        $list = $this->listWidget($picker);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();

            $openScreen = $harness->plainScreenText();
            $this->assertStringContainsString('d deletes', $openScreen);
            $this->assertStringContainsString('#'.$deleteId.' — Delete target', $openScreen);

            // SQLite second-resolution timestamps can tie-break either way; land on delete target.
            $this->selectSession($harness, $list, $deleteId);

            $harness->sendInput('d');
            $confirmScreen = $harness->plainScreenText();
            $this->assertStringContainsString(
                \sprintf('Delete session #%s — Delete target?', $deleteId),
                $confirmScreen,
            );
            $this->assertStringContainsString('Yes', $confirmScreen);
            $this->assertTrue($this->store->exists($deleteId), 'Confirm step must not delete yet');

            $harness->sendInput("\n"); // Enter on Yes
            $afterScreen = $harness->plainScreenText();
            $this->assertTrue($picker->isOpen(), 'Picker stays open after delete');
            $this->assertFalse($this->store->exists($deleteId));
            $this->assertDirectoryDoesNotExist($this->store->resolveSessionsBasePath().'/'.$deleteId);
            $this->assertStringNotContainsString('#'.$deleteId.' — Delete target', $afterScreen);
            $this->assertStringContainsString('#'.$activeId.' — Active keep', $afterScreen);
            $this->assertStringContainsString('d deletes', $afterScreen);
            $this->assertStringContainsString('Deleted session #'.$deleteId, $harness->screen()->workingMessage());
        } finally {
            $harness->stopInputLoop();
        }
    }

    #[Test]
    public function testDeleteActiveSessionIsBlocked(): void
    {
        $activeId = $this->store->createSession('Active blocked');
        $otherId = $this->store->createSession('Other session');

        $switch = $this->createMock(TuiSessionSwitchServiceInterface::class);
        $switch->expects($this->never())->method('requestResume');

        $harness = new VirtualTuiHarness(sessionId: $activeId, columns: 140, rows: 40);
        $picker = new SessionPickerController($harness->tui(), $harness->screen(), $this->store, $switch);
        $picker->open();

        $list = $this->listWidget($picker);
        $harness->startInputLoop();
        try {
            $harness->tui()->setFocus($list);
            $harness->render();

            $this->selectSession($harness, $list, $activeId);

            $harness->sendInput('d');
            $screen = $harness->plainScreenText();
            $this->assertStringContainsString('Cannot delete the current/active session', $harness->screen()->workingMessage());
            $this->assertStringNotContainsString('Delete session #'.$activeId, $screen);
            $this->assertTrue($this->store->exists($activeId));
            $this->assertTrue($this->store->exists($otherId));
            $this->assertTrue($picker->isOpen());
            $this->assertStringContainsString('d deletes', $screen);
        } finally {
            $harness->stopInputLoop();
        }
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
