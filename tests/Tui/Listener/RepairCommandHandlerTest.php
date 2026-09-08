<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\RepairCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RepairCommandHandlerTest extends TestCase
{
    #[Test]
    public function rejectsArguments(): void
    {
        $handler = new RepairCommandHandler(new RepairCommandSpyClient(), new TuiSessionState('repair'), new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', 'apply', '/repair apply'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('/repair does not accept arguments.', $result->text);
        $this->assertSame('error', $result->style);
    }

    #[Test]
    public function returnsNoActiveSessionWhenRunIdMissing(): void
    {
        $handler = new RepairCommandHandler(new RepairCommandSpyClient(), new TuiSessionState('repair'), new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('No active session to repair.', $result->text);
    }

    #[Test]
    public function mapsTypedRefusalToSafeUserMessage(): void
    {
        $client = new RepairCommandSpyClient();
        $client->result = new RepairResult(
            repairableStaleCancellationDetected: true,
            staleCancellationRepaired: false,
            message: 'internal',
            refusalReason: SessionRepairRefusalReasonEnum::DuplicateSequences,
        );

        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-1');
        $handler = new RepairCommandHandler($client, $state, new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repair refused: duplicate event sequences.', $result->text);
        $this->assertSame('error', $result->style);
        $this->assertSame('run-1', $client->lastRepairRunId);
        $this->assertTrue($client->lastRepairApply);
    }

    #[Test]
    public function reportsActiveOperationRedrive(): void
    {
        $client = new RepairCommandSpyClient();
        $client->result = new RepairResult(false, false, 'internal', activeOperationsRedriven: 1);
        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-redrive');
        $handler = new RepairCommandHandler($client, $state, new NullLogger());

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repaired: active operation redriven.', $result->text);
        $this->assertSame('system', $result->style);
    }

    #[Test]
    public function logsStructuredDegradationWhenRepairThrows(): void
    {
        $client = new RepairCommandSpyClient();
        $client->throwOnRepair = true;

        $logger = new TestLogger();
        $state = new TuiSessionState('repair');
        $state->handle = new RunHandle('run-err');
        $handler = new RepairCommandHandler($client, $state, $logger);

        $result = $handler->handle(new SlashCommand('repair', '', '/repair'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Session repair failed due to an internal error.', $result->text);
        $this->assertCount(1, $logger->records);
        $this->assertSame('session_repair.command_failed', $logger->records[0]['message']);
        $this->assertSame('run-err', $logger->records[0]['context']['run_id']);
        $this->assertSame(\RuntimeException::class, $logger->records[0]['context']['exception_class']);
        $this->assertArrayNotHasKey('exception', $logger->records[0]['context']);
        $this->assertArrayNotHasKey('exception_message', $logger->records[0]['context']);
    }
}

final class RepairCommandSpyClient implements AgentSessionClient
{
    public ?string $lastRepairRunId = null;
    public ?bool $lastRepairApply = null;
    public bool $throwOnRepair = false;
    public RepairResult $result;

    public function __construct()
    {
        $this->result = new RepairResult(false, false, 'No repairable corruption detected.');
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('Unexpected start()');
    }

    public function attach(string $runId): RunHandle
    {
        throw new \RuntimeException('Unexpected attach()');
    }

    public function send(string $runId, UserCommand $command): void
    {
        throw new \RuntimeException('Unexpected send()');
    }

    public function beginObservingChildRun(string $childRunId): void
    {
    }

    public function endObservingChildRun(string $childRunId): void
    {
    }

    public function events(string $runId, int $afterSeq = 0): iterable
    {
        return [];
    }

    public function shutdown(): void
    {
    }

    public function refreshMcpCatalog(string $runId): void
    {
    }

    public function cancel(string $runId): void
    {
        throw new \RuntimeException('Unexpected cancel()');
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \RuntimeException('Unexpected shellExecute()');
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        throw new \RuntimeException('Unexpected compact()');
    }

    public function repair(string $runId, bool $apply = true): RepairResult
    {
        $this->lastRepairRunId = $runId;
        $this->lastRepairApply = $apply;
        if ($this->throwOnRepair) {
            throw new \RuntimeException('corrupt json with secrets');
        }

        return $this->result;
    }
}
