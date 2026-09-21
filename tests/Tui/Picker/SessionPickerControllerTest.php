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
}
