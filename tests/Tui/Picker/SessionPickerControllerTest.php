<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Picker;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionPickerController::class)]
final class SessionPickerControllerTest extends TestCase
{
    private function createTheme(): DefaultTheme
    {
        return new DefaultTheme(new ThemePalette('test'));
    }

    #[Test]
    public function testIsOpenIsFalseInitially(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
        );
        $controller = new SessionPickerController($sessionStore, $switch);

        self::assertFalse($controller->isOpen());
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

        $theme = $this->createTheme();
        $items = SessionPickerController::buildItemsStatic($sessions, $theme);

        self::assertCount(2, $items);
        self::assertSame('1', $items[0]['value']);
        self::assertStringContainsString('My Coding Session', $items[0]['label']);
        self::assertStringContainsString('#1', $items[0]['label']);
        self::assertSame('42', $items[1]['value']);
        self::assertStringContainsString('Fix Auth Bug', $items[1]['label']);
        self::assertStringContainsString('#42', $items[1]['label']);
    }

    #[Test]
    public function testBuildItemsStaticReturnsEmptyForEmptyInput(): void
    {
        $items = SessionPickerController::buildItemsStatic([], $this->createTheme());

        self::assertSame([], $items);
    }

    #[Test]
    public function testFindItemIndex(): void
    {
        $items = [
            ['value' => '10', 'label' => 'A'],
            ['value' => '20', 'label' => 'B'],
            ['value' => '30', 'label' => 'C'],
        ];

        self::assertSame(0, SessionPickerController::findItemIndex($items, '10'));
        self::assertSame(1, SessionPickerController::findItemIndex($items, '20'));
        self::assertSame(2, SessionPickerController::findItemIndex($items, '30'));
        self::assertNull(SessionPickerController::findItemIndex($items, '999'));
    }

    #[Test]
    public function testFindItemIndexReturnsNullForEmptyList(): void
    {
        self::assertNull(SessionPickerController::findItemIndex([], '1'));
    }

    #[Test]
    public function testApplySelectEffectCallsSwitchResume(): void
    {
        $switch = new class implements TuiSessionSwitchServiceInterface {
            public ?string $resumedSessionId = null;

            public function bindForIteration(
                \Symfony\Component\Tui\Tui $tui,
                \Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient $client,
                \Ineersa\Tui\Runtime\TuiSessionState $state,
            ): void {
            }

            public function requestResume(string $sessionId): void
            {
                $this->resumedSessionId = $sessionId;
            }

            public function requestNewDraft(
                ?\Ineersa\CodingAgent\Runtime\Contract\StartRunRequest $request = null,
            ): void {
            }

            public function hasPendingSwitch(): bool
            {
                return false;
            }
        };

        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
        );
        $controller = new SessionPickerController($sessionStore, $switch);

        $controller->applySelectEffect('42');

        self::assertSame('42', $switch->resumedSessionId);
    }

    #[Test]
    public function testClosePickerOnUnopenedControllerIsNoOp(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
        );
        $controller = new SessionPickerController($sessionStore, $switch);

        // Should not throw when no picker is open
        $controller->closePicker();

        self::assertFalse($controller->isOpen());
    }
}
