<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RepairResult;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\RepairHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RepairHandler::class)]
final class RepairHandlerTest extends TestCase
{
    public function testDispatchesRepairAndEmitsCompletedPayload(): void
    {
        $client = new RepairSpySessionClient();
        $client->result = new RepairResult(
            repairableStaleCancellationDetected: false,
            staleCancellationRepaired: false,
            message: 'Active operation redriven.',
            activeOperationsRedriven: 2,
        );
        $handler = new RepairHandler($client);
        $emitted = [];

        $command = new RuntimeCommand(
            id: 'cmd_repair_1',
            type: 'repair',
            runId: 'run-123',
            payload: ['apply' => true],
        );
        $event = new ControllerCommandEvent($command, static function (RuntimeEvent $runtimeEvent) use (&$emitted): void {
            $emitted[] = $runtimeEvent;
        });

        $handler($event);

        $this->assertSame('run-123', $client->lastRepairRunId);
        $this->assertTrue($client->lastRepairApply);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::SessionRepairCompleted->value, $emitted[0]->type);
        $this->assertSame('completed', $emitted[0]->payload['status'] ?? null);
        $this->assertSame('cmd_repair_1', $emitted[0]->payload['commandId'] ?? null);
        $this->assertSame(2, $emitted[0]->payload['active_operations_redriven'] ?? null);
        $this->assertArrayHasKey('refusal_reason', $emitted[0]->payload);
        $this->assertNull($emitted[0]->payload['refusal_reason']);
    }

    public function testEmitsRefusalScalarsWithoutExceptionDetails(): void
    {
        $client = new RepairSpySessionClient();
        $client->result = new RepairResult(
            repairableStaleCancellationDetected: true,
            staleCancellationRepaired: false,
            message: 'internal',
            refusalReason: SessionRepairRefusalReasonEnum::ActiveStreaming,
        );
        $handler = new RepairHandler($client);
        $emitted = [];

        $command = new RuntimeCommand(id: 'cmd_repair_2', type: 'repair', runId: 'run-456');
        $event = new ControllerCommandEvent($command, static function (RuntimeEvent $runtimeEvent) use (&$emitted): void {
            $emitted[] = $runtimeEvent;
        });
        $handler($event);

        $this->assertSame(SessionRepairRefusalReasonEnum::ActiveStreaming->value, $emitted[0]->payload['refusal_reason'] ?? null);
        $this->assertArrayNotHasKey('exception_class', $emitted[0]->payload);
        $this->assertArrayNotHasKey('exception_message', $emitted[0]->payload);
    }

    public function testEmitsFailedStatusWithoutExceptionMessageOnThrow(): void
    {
        $client = new RepairSpySessionClient();
        $client->throwOnRepair = true;
        $logger = new TestLogger();
        $handler = new RepairHandler($client, $logger);
        $emitted = [];

        $command = new RuntimeCommand(id: 'cmd_repair_3', type: 'repair', runId: 'run-789');
        $event = new ControllerCommandEvent($command, static function (RuntimeEvent $runtimeEvent) use (&$emitted): void {
            $emitted[] = $runtimeEvent;
        });
        $handler($event);

        $this->assertSame('failed', $emitted[0]->payload['status'] ?? null);
        $this->assertSame(\RuntimeException::class, $emitted[0]->payload['exception_class'] ?? null);
        $this->assertArrayNotHasKey('exception_message', $emitted[0]->payload);
        $errorRecords = array_values(array_filter(
            $logger->records,
            static fn (array $record): bool => 'session_repair.controller_failed' === $record['message'],
        ));
        $this->assertCount(1, $errorRecords);
        $this->assertArrayNotHasKey('exception_message', $errorRecords[0]['context']);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $client = new RepairSpySessionClient();
        $handler = new RepairHandler($client);
        $emitted = [];

        $command = new RuntimeCommand(id: 'cmd_repair_4', type: 'repair', runId: '');
        $event = new ControllerCommandEvent($command, static function (RuntimeEvent $runtimeEvent) use (&$emitted): void {
            $emitted[] = $runtimeEvent;
        });
        $handler($event);

        $this->assertNull($client->lastRepairRunId);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emitted[0]->type);
        $this->assertStringContainsString('repair requires runId', $emitted[0]->payload['error'] ?? '');
    }

    public function testIgnoresNonRepairCommands(): void
    {
        $client = new RepairSpySessionClient();
        $handler = new RepairHandler($client);

        $command = new RuntimeCommand(id: 'cmd_other', type: 'compact', runId: 'run-1');
        $handler(new ControllerCommandEvent($command, static function (): void {}));

        $this->assertNull($client->lastRepairRunId);
    }
}

/**
 * @internal test helper
 */
final class RepairSpySessionClient implements AgentSessionClient
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
            throw new \RuntimeException('secret stack');
        }

        return $this->result;
    }
}
