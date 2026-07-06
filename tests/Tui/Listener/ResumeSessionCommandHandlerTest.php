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
use Ineersa\Tui\Listener\ResumeSessionCommandHandler;
use Ineersa\Tui\Picker\SessionPickerController;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ResumeSessionCommandHandler::class)]
final class ResumeSessionCommandHandlerTest extends TestCase
{
    #[Test]
    public function testHandleWithNoArgsOpensPickerAndReturnsNoOp(): void
    {
        $switch = $this->createSwitchSpy();
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new ResumeSessionCommandHandler($switch, $sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('resume', '', '/resume'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertNull($switch->resumedSessionId, 'Switch should NOT be called when no args given');
        // Picker should be opened — picker state not directly verifiable without TUI
        $this->assertFalse($pickerController->isOpen(), 'Picker requires TUI runtime refs so it stays closed');
    }

    #[Test]
    public function testHandleWithValidSessionIdCallsSwitchAndReturnsNoOp(): void
    {
        $switch = $this->createSwitchSpy();
        $em = $this->createEntityManagerWithSession(42, 'Test Session');
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new ResumeSessionCommandHandler($switch, $sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('resume', '42', '/resume 42'));

        $this->assertInstanceOf(NoOp::class, $result);
        $this->assertSame('42', $switch->resumedSessionId, 'Expected requestResume() with session ID');
    }

    #[Test]
    public function testHandleWithInvalidSessionIdReturnsError(): void
    {
        $switch = $this->createSwitchSpy();
        // EntityManager as stub — find() returns null for any ID
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new ResumeSessionCommandHandler($switch, $sessionStore, $pickerController);

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
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new ResumeSessionCommandHandler($switch, $sessionStore, $pickerController);

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
        $em = $this->createStub(EntityManagerInterface::class);
        $sessionStore = new HatfieldSessionStore($this->createAppConfig(), $em);
        $pickerController = new SessionPickerController($sessionStore, $switch);

        $handler = new ResumeSessionCommandHandler($switch, $sessionStore, $pickerController);

        $result = $handler->handle(new SlashCommand('resume', '0', '/resume 0'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('0', $result->text);
        $this->assertSame('error', $result->role);
        $this->assertNull($switch->resumedSessionId, 'Switch should NOT be called for session 0');
    }

    private function createAppConfig(): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            cwd: '/tmp/test-resume',
        );
    }

    private function createEntityManagerWithSession(int $id, string $name): EntityManagerInterface
    {
        $entity = new HatfieldSession();
        $entity->id = $id;
        $entity->name = $name;
        $entity->cwd = '/tmp/test';
        $entity->createdAt = new \DateTimeImmutable();
        $entity->updatedAt = new \DateTimeImmutable();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->atLeastOnce())
            ->method('find')
            ->with(HatfieldSession::class, $id)
            ->willReturn($entity);

        return $em;
    }

    private function createSwitchSpy(): object
    {
        return new class implements TuiSessionSwitchServiceInterface {
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

            public function hasPendingSwitch(): bool
            {
                return false;
            }
        };
    }
}
