<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Editor\PromptEditor;
use Ineersa\Tui\Listener\RenameSessionCommandHandler;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use Ineersa\Tui\Screen\ChatScreen;
use Ineersa\Tui\Theme\DefaultTheme;
use Ineersa\Tui\Theme\ThemePalette;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Uses IsolatedKernelTestCase because HatfieldSessionStore now routes
 * exists()/deleteSession() through concrete HatfieldSessionRepository COUNT
 * helpers, and that repository is final.
 */
#[CoversClass(RenameSessionCommandHandler::class)]
final class RenameSessionCommandHandlerTest extends IsolatedKernelTestCase
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
    public function testHandleWithValidSessionAndNameReturnsSuccess(): void
    {
        $sessionId = $this->sessionStore->createSession('Original Name');
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand(
            'rename',
            $sessionId.' New Name',
            '/rename '.$sessionId.' New Name',
        ));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString($sessionId, $result->text);
        $this->assertStringContainsString('New Name', $result->text);
        $this->assertSame('system', $result->role);

        $session = $this->sessionStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('New Name', $session->name);
    }

    #[Test]
    public function testHandleWithValidSessionAndMultipartNameReturnsSuccess(): void
    {
        $sessionId = $this->sessionStore->createSession('Old');
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand(
            'rename',
            $sessionId.' My Awesome Session',
            '/rename '.$sessionId.' My Awesome Session',
        ));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString($sessionId, $result->text);
        $this->assertStringContainsString('My Awesome Session', $result->text);

        $session = $this->sessionStore->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('My Awesome Session', $session->name);
    }

    #[Test]
    public function testHandleWithMissingNameReturnsErrorWithHint(): void
    {
        $sessionId = $this->sessionStore->createSession('Original');
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand('rename', $sessionId, '/rename '.$sessionId));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Provide a name', $result->text);
        $this->assertStringContainsString('/rename '.$sessionId, $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithInvalidSessionIdReturnsError(): void
    {
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand('rename', '999 NewName', '/rename 999 NewName'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('999', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithMalformedSessionIdReturnsError(): void
    {
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand('rename', 'abc NewName', '/rename abc NewName'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('abc', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithSessionIdZeroReturnsError(): void
    {
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand('rename', '0 NewName', '/rename 0 NewName'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('0', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithWhitespaceOnlyNameReturnsError(): void
    {
        $sessionId = $this->sessionStore->createSession('Original');
        $handler = $this->createHandler();

        $result = $handler->handle(new SlashCommand(
            'rename',
            $sessionId."   \t  ",
            '/rename '.$sessionId,
        ));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Provide a name', $result->text);
        $this->assertStringContainsString('/rename '.$sessionId, $result->text);
        $this->assertSame('error', $result->role);
    }

    private function createHandler(): RenameSessionCommandHandler
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
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

        return new RenameSessionCommandHandler($this->sessionStore, $pickerController);
    }
}
