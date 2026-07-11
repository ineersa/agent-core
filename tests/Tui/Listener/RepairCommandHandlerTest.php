<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Session\Repair\RepairResult;
use Ineersa\CodingAgent\Session\Repair\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Session\Repair\SessionRepairServiceInterface;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\RepairCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class RepairCommandHandlerTest extends TestCase
{
    #[Test]
    public function rejectsArguments(): void
    {
        $handler = new RepairCommandHandler($this->createStub(SessionRepairServiceInterface::class), new TuiSessionState('repair'));

        $result = $handler->handle(new SlashCommand('repair', 'apply', '/repair apply'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('/repair does not accept arguments.', $result->text);
        $this->assertSame('error', $result->style);
    }

    #[Test]
    public function returnsNoActiveSessionWhenRunIdMissing(): void
    {
        $handler = new RepairCommandHandler($this->createStub(SessionRepairServiceInterface::class), new TuiSessionState('repair'));

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('No active session to repair.', $result->text);
    }

    #[Test]
    public function mapsTypedRefusalToSafeUserMessage(): void
    {
        $service = $this->createStub(SessionRepairServiceInterface::class);
        $service->method('repair')->willReturn(new RepairResult(
            needsRepair: true,
            staleCancellationRepaired: false,
            terminalEventsAppended: 0,
            replayOk: false,
            message: 'internal',
            duplicateSeqs: [3],
            backupEventsPath: null,
            refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
        ));

        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-1');
        $handler = new RepairCommandHandler($service, $state);

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repair refused: duplicate event sequences.', $result->text);
        $this->assertSame('error', $result->style);
    }
}
