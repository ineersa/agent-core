<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\Tui\Command\NoOp;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Listener\NewSessionCommandHandler;
use Ineersa\Tui\Runtime\Contract\TuiSessionSwitchServiceInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(NewSessionCommandHandler::class)]
final class NewSessionCommandHandlerTest extends TestCase
{
    #[Test]
    public function testHandleCallsRequestNewDraftAndReturnsNoOp(): void
    {
        $switch = new class implements TuiSessionSwitchServiceInterface {
            public bool $draftRequested = false;

            public function bindForIteration(
                \Symfony\Component\Tui\Tui $tui,
                \Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient $client,
                \Ineersa\Tui\Runtime\TuiSessionState $state,
            ): void {
            }

            public function requestResume(string $sessionId): void
            {
            }

            public function requestNewDraft(
                ?\Ineersa\CodingAgent\Runtime\Contract\StartRunRequest $request = null,
            ): void {
                $this->draftRequested = true;
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

        $handler = new NewSessionCommandHandler($switch);

        $result = $handler->handle(new SlashCommand('new', '', '/new'));

        self::assertInstanceOf(NoOp::class, $result);
        self::assertTrue($switch->draftRequested, 'Expected requestNewDraft() to be called');
    }

    #[Test]
    public function testHandleReturnsNoOpWithArgsIgnored(): void
    {
        $switch = $this->createStub(TuiSessionSwitchServiceInterface::class);
        $handler = new NewSessionCommandHandler($switch);

        // /new with extra args — handler ignores them
        $result = $handler->handle(new SlashCommand('new', 'some args', '/new some args'));

        self::assertInstanceOf(NoOp::class, $result);
    }
}
