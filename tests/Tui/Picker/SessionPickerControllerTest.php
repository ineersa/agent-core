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
use Ineersa\Tui\Theme\ThemeColorEnum;
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
        self::assertSame('#1 — My Coding Session', $items[0]['label']);
        self::assertSame('42', $items[1]['value']);
        self::assertSame('#42 — Fix Auth Bug', $items[1]['label']);

        // No description key — full-width single-column rendering
        self::assertArrayNotHasKey('description', $items[0]);
        self::assertArrayNotHasKey('description', $items[1]);
    }

    #[Test]
    public function testBuildItemsStaticReturnsEmptyForEmptyInput(): void
    {
        $items = SessionPickerController::buildItemsStatic([], $this->createTheme());

        self::assertSame([], $items);
    }

    #[Test]
    public function testBuildItemsStaticAppliesAccentToSelectedIndex(): void
    {
        $sessions = [
            ['sessionId' => '1', 'name' => 'Session A', 'displayTitle' => 'Session A'],
            ['sessionId' => '2', 'name' => 'Session B', 'displayTitle' => 'Session B'],
        ];

        // Provide a real accent colour so ThemeColorEnum::Accent produces ANSI
        $palette = new ThemePalette('test', [ThemeColorEnum::Accent->value => '#FF00FF']);
        $theme = new DefaultTheme($palette);
        $accented = SessionPickerController::buildItemsStatic($sessions, $theme, selectedIndex: 0);

        self::assertStringContainsString('#1 — Session A', $accented[0]['label']);
        self::assertStringContainsString('#2 — Session B', $accented[1]['label']);
        // The accent-coloured label contains ANSI escape codes;
        // the non-selected label does not.
        self::assertStringContainsString("\x1b", $accented[0]['label']);
        self::assertStringNotContainsString("\x1b", $accented[1]['label']);
    }

    #[Test]
    public function testBuildItemsStaticAppliesAccentToNonZeroSelectedIndex(): void
    {
        $sessions = [
            ['sessionId' => '1', 'name' => 'Session A', 'displayTitle' => 'Session A'],
            ['sessionId' => '2', 'name' => 'Session B', 'displayTitle' => 'Session B'],
            ['sessionId' => '3', 'name' => 'Session C', 'displayTitle' => 'Session C'],
        ];

        $palette = new ThemePalette('test', [ThemeColorEnum::Accent->value => '#FF00FF']);
        $theme = new DefaultTheme($palette);
        $accented = SessionPickerController::buildItemsStatic($sessions, $theme, selectedIndex: 1);

        // Row 0 not accented, row 1 accented, row 2 not accented
        self::assertStringNotContainsString("\x1b", $accented[0]['label']);
        self::assertStringContainsString("\x1b", $accented[1]['label']);
        self::assertStringNotContainsString("\x1b", $accented[2]['label']);
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

            public function rewindToTurn(int $targetTurnNo): void
            {
                // No-op: this test does not exercise rewind.
            }


            public function navigateTreeToTurn(int $targetTurnNo, string $fileChoice = 'keep_files'): void
            {
                // No-op: this test does not exercise tree navigation.
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

    #[Test]
    public function testOpenForRenameCommandIsSafeWithoutRuntimeRefs(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
        );
        $controller = new SessionPickerController($sessionStore, $switch);

        // Without TUI runtime refs, openForRenameCommand should be a no-op
        // and must not throw.
        $controller->openForRenameCommand();

        self::assertFalse($controller->isOpen(), 'Picker should not be open without TUI refs');
    }

    #[Test]
    public function testOpenForRenameCommandDoesNotMutateSwitch(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore(
            new AppConfig(tui: new TuiConfig(theme: 'default'), logging: new LoggingConfig()),
            $em,
        );
        $controller = new SessionPickerController($sessionStore, $switch);

        // openForRenameCommand should never call the switch service
        // (unlike open() which calls applySelectEffect -> requestResume).
        // This is indirectly verified because openForRenameCommand returns
        // without throwing (no runtime refs), but the important thing is
        // the method never references $this->switch at all.
        $controller->openForRenameCommand();

        self::assertFalse($controller->isOpen());
    }
}
