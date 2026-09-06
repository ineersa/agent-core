<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use Ineersa\Tui\Transcript\TranscriptDisplayConfig;
use Ineersa\Tui\Transcript\TranscriptDisplayState;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionPickerController::class)]
final class SessionPickerControllerTest extends TestCase
{
    #[Test]
    public function testIsOpenIsFalseInitially(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $controller = new SessionPickerController($this->tui(), $this->screen(), $sessionStore, $switch);

        $this->assertFalse(self::pickerOpen($controller));
    }

    #[Test]
    public function testBuildItemsStaticFormatsSessionsCorrectly(): void
    {
        $sessions = [
            [
                'sessionId' => '1',
                'name' => 'My Coding Session',
                'displayTitle' => 'My Coding Session',
            ],
            [
                'sessionId' => '42',
                'name' => 'Fix Auth Bug',
                'displayTitle' => 'Fix Auth Bug',
            ],
        ];

        $items = SessionPickerController::buildItemsStatic($sessions);

        $this->assertCount(2, $items);
        $this->assertSame('1', $items[0]['value']);
        $this->assertSame('#1 — My Coding Session', $items[0]['label']);
        $this->assertSame('42', $items[1]['value']);
        $this->assertSame('#42 — Fix Auth Bug', $items[1]['label']);

        // No description key — full-width single-column rendering
        $this->assertArrayNotHasKey('description', $items[0]);
        $this->assertArrayNotHasKey('description', $items[1]);
    }

    #[Test]
    public function testBuildItemsStaticReturnsEmptyForEmptyInput(): void
    {
        $items = SessionPickerController::buildItemsStatic([]);

        $this->assertSame([], $items);
    }

    #[Test]
    public function testApplySelectEffectCallsSwitchResume(): void
    {
        $switch = new class implements TuiSessionSwitchServiceInterface {
            public ?string $resumedSessionId = null;

            public function requestResume(string $sessionId): void
            {
                $this->resumedSessionId = $sessionId;
            }

            public function requestNewDraft(
                ?\Ineersa\CodingAgent\Runtime\Contract\StartRunRequest $request = null,
            ): void {
            }

            public function selectHistoryTurn(int $targetTurnNo): void
            {
                // No-op: this test does not exercise history selection.
            }

            public function requestReload(string $sessionId): void
            {
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $controller = new SessionPickerController($this->tui(), $this->screen(), $sessionStore, $switch);

        $controller->applySelectEffect('42');

        $this->assertSame('42', $switch->resumedSessionId);
    }

    #[Test]
    public function testClosePickerOnUnopenedControllerIsNoOp(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $controller = new SessionPickerController($this->tui(), $this->screen(), $sessionStore, $switch);

        // Should not throw when no picker is open
        $controller->closePicker();

        $this->assertFalse(self::pickerOpen($controller));
    }

    #[Test]
    private function createTheme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test'));
    }

    private function tui(): \Symfony\Component\Tui\Tui
    {
        return new \Symfony\Component\Tui\Tui();
    }

    private function screen(): ChatScreen
    {
        return new ChatScreen(
            $this->createTheme(),
            'test-session',
            new PromptEditor(),
            new TranscriptDisplayConfig(),
            new TranscriptDisplayState(),
        );
    }

    private static function pickerOpen(SessionPickerController $controller): bool
    {
        $overlayRef = new \ReflectionProperty($controller, 'overlay');
        $overlay = $overlayRef->getValue($controller);

        return null !== $overlay && $overlay->isOpen();
    }
}
