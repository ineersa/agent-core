<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\RenameSessionCommandHandler;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(RenameSessionCommandHandler::class)]
final class RenameSessionCommandHandlerTest extends TestCase
{
    #[Test]
    public function testHandleWithNoArgsOpensPickerAndReturnsNoOp(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', '', '/rename'));

        $this->assertInstanceOf(NoOp::class, $result);
        // Picker should be opened for rename — not directly verifiable without TUI refs
    }

    #[Test]
    public function testHandleWithValidSessionAndNameReturnsSuccess(): void
    {
        $sessionStore = $this->createSessionStoreWithSession(42, 'Original Name');
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', '42 New Name', '/rename 42 New Name'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('42', $result->text);
        $this->assertStringContainsString('New Name', $result->text);
        $this->assertSame('system', $result->role);
    }

    #[Test]
    public function testHandleWithValidSessionAndMultipartNameReturnsSuccess(): void
    {
        $sessionStore = $this->createSessionStoreWithSession(7, 'Old');
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', '7 My Awesome Session', '/rename 7 My Awesome Session'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('7', $result->text);
        $this->assertStringContainsString('My Awesome Session', $result->text);
    }

    #[Test]
    public function testHandleWithMissingNameReturnsErrorWithHint(): void
    {
        $sessionStore = $this->createSessionStoreWithSession(42, 'Original');
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', '42', '/rename 42'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Provide a name', $result->text);
        $this->assertStringContainsString('/rename 42', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithInvalidSessionIdReturnsError(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', '999 NewName', '/rename 999 NewName'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('999', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithMalformedSessionIdReturnsError(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', 'abc NewName', '/rename abc NewName'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('abc', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithSessionIdZeroReturnsError(): void
    {
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', '0 NewName', '/rename 0 NewName'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('0', $result->text);
        $this->assertSame('error', $result->role);
    }

    #[Test]
    public function testHandleWithWhitespaceOnlyNameReturnsError(): void
    {
        $sessionStore = $this->createSessionStoreWithSession(42, 'Original');
        $switch = $this->createSwitchStub();
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new RenameSessionCommandHandler($sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('rename', "42   \t  ", '/rename 42'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Provide a name', $result->text);
        $this->assertStringContainsString('/rename 42', $result->text);
        $this->assertSame('error', $result->role);
    }

    private function createAppConfig(): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: '/tmp/test-rename',
        );
    }

    private function createSessionStoreWithSession(int $id, string $name): HatfieldSessionStore
    {
        $entity = new HatfieldSession();
        $entity->id = $id;
        $entity->name = $name;
        $entity->cwd = '/tmp/test';
        $entity->createdAt = new \DateTimeImmutable();
        $entity->updatedAt = new \DateTimeImmutable();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class, mixed $idParam) use ($id, $entity): ?HatfieldSession {
                return HatfieldSession::class === $class && $idParam === $id ? $entity : null;
            },
        );

        // flush() may or may not be called depending on whether the
        // handler reaches updateMetadata().  Stub is fine since we
        // test the return value, not flush() invocation count.

        return new HatfieldSessionStore($this->createAppConfig(), $em);
    }

    private function createSwitchStub(): TuiSessionSwitchServiceInterface
    {
        return $this->createStub(TuiSessionSwitchServiceInterface::class);
    }
}
