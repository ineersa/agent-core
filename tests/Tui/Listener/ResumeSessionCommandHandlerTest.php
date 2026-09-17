<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\ResumeSessionCommandHandler;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Uses IsolatedKernelTestCase because HatfieldSessionStore::exists() now uses
 * concrete HatfieldSessionRepository::existsById(), and that repository is final.
 */
#[CoversClass(ResumeSessionCommandHandler::class)]
final class ResumeSessionCommandHandlerTest extends IsolatedKernelTestCase
{
    private HatfieldSessionStore $sessionStore;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var HatfieldSessionStore $sessionStore */
        $sessionStore = self::getContainer()->get(HatfieldSessionStore::class);
        $this->sessionStore = $sessionStore;
    }

    #[Test]
    public function testHandleWithValidSessionIdCallsSwitchAndReturnsNoOp(): void
    {
        $sessionId = $this->sessionStore->createSession('Test Session');
        $switch = $this->createSwitchSpy();
        $handler = $this->createHandler($switch);

        $result = $handler->handle(new SlashCommand('resume', $sessionId, '/resume '.$sessionId));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertSame($sessionId, $switch->resumedSessionId, 'Expected requestResume() with session ID');
    }

    #[Test]
    public function testHandleWithInvalidSessionIdReturnsError(): void
    {
        $switch = $this->createSwitchSpy();
        $handler = $this->createHandler($switch);

        $result = $handler->handle(new SlashCommand('resume', '999', '/resume 999'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('999', $result->text);
        $this->assertSame('error', $result->role);
        $this->assertNull($switch->resumedSessionId, 'Switch should NOT be called for invalid session');
    }

    #[Test]
    public function testHandleWithMalformedSessionIdReturnsError(): void
    {
        $switch = $this->createSwitchSpy();
        $handler = $this->createHandler($switch);

        $result = $handler->handle(new SlashCommand('resume', '42 extra', '/resume 42 extra'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('42 extra', $result->text);
        $this->assertSame('error', $result->role);
        $this->assertNull($switch->resumedSessionId, 'Switch should NOT be called for malformed ID');
    }

    #[Test]
    public function testHandleWithSessionIdZeroReturnsError(): void
    {
        $switch = $this->createSwitchSpy();
        $handler = $this->createHandler($switch);

        $result = $handler->handle(new SlashCommand('resume', '0', '/resume 0'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('0', $result->text);
        $this->assertSame('error', $result->role);
        $this->assertNull($switch->resumedSessionId, 'Switch should NOT be called for session 0');
    }

    private function createHandler(TuiSessionSwitchServiceInterface $switch): ResumeSessionCommandHandler
    {
        $pickerController = new SessionPickerController(
            new \Symfony\Component\Tui\Tui(),
            new ChatScreen(
                new DefaultTheme(new ThemePalette('test')),
                'test-session',
                new PromptEditor(),
            ),
            $this->sessionStore,
            $switch,
        );

        return new ResumeSessionCommandHandler($switch, $this->sessionStore, $pickerController);
    }

    private function createSwitchSpy(): object
    {
        return new class implements TuiSessionSwitchServiceInterface {
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
    }
}
