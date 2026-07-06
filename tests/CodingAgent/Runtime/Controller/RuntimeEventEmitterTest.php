<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\CursorAwareAgentSessionClientInterface;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\RuntimeExceptionBoundary;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter;
use Ineersa\CodingAgent\Runtime\Protocol\JsonlCodec;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * @covers \Ineersa\CodingAgent\Runtime\Controller\RuntimeEventEmitter
 */
final class RuntimeEventEmitterTest extends TestCase
{
    public function testOpenStdoutOpensWritableStream(): void
    {
        $emitter = $this->createEmitter();
        $emitter->openStdout();

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: [],
        ));

        $this->assertFalse($emitter->isShuttingDown());
    }

    public function testEmitWithoutOpenStdoutDoesNotThrow(): void
    {
        $emitter = $this->createEmitter();

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RuntimeReady->value,
            runId: '',
            seq: 0,
            payload: [],
        ));

        $this->assertFalse($emitter->isShuttingDown());
    }

    public function testShutdownSetsFlag(): void
    {
        $emitter = $this->createEmitter();
        $this->assertFalse($emitter->isShuttingDown());

        $emitter->shutdown();
        $this->assertTrue($emitter->isShuttingDown());
    }

    public function testEmitWithNullPersisterDoesNotThrow(): void
    {
        $emitter = $this->createEmitter();

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: 'test-run',
            seq: 1,
            payload: [],
        ));

        $this->assertFalse($emitter->isShuttingDown());
    }

    public function testDrainRetriesAfterTransientFailureAndForwardsLaterEvents(): void
    {
        $runId = '4';
        $client = new FlakySeqDrainAgentSessionClient(
            throwOnCall: 2,
            eventsByCall: [
                1 => [
                    new RuntimeEvent(RuntimeEventTypeEnum::CancellationRequested->value, $runId, 148, []),
                ],
                3 => [
                    new RuntimeEvent(RuntimeEventTypeEnum::ToolExecutionCancelled->value, $runId, 151, []),
                    new RuntimeEvent(RuntimeEventTypeEnum::ToolExecutionCancelled->value, $runId, 155, []),
                    new RuntimeEvent(RuntimeEventTypeEnum::RunCancelled->value, $runId, 159, []),
                ],
            ],
        );

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->atLeastOnce())
            ->method('warning')
            ->with(
                'Canonical event drain failed; will retry on next tick',
                $this->callback(static function (array $context) use ($runId): bool {
                    return ($context['run_id'] ?? null) === $runId
                        && ($context['component'] ?? null) === 'RuntimeEventEmitter'
                        && isset($context['exception_class'], $context['last_forwarded_seq']);
                }),
            );

        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $emitter = new RuntimeEventEmitter($client, $boundary, $logger);
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $runId,
            seq: 1,
            payload: [],
        ));

        $emitter->drainRegisteredRunsOnce();
        $this->assertSame(1, $client->eventsCallCount);

        $emitter->drainRegisteredRunsOnce();
        $this->assertSame(2, $client->eventsCallCount);

        $emitter->drainRegisteredRunsOnce();
        $this->assertSame(3, $client->eventsCallCount);

        $stdout = $this->stdoutHandle($emitter);
        rewind($stdout);
        $raw = stream_get_contents($stdout) ?: '';
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
        $decoded = array_map(static fn (string $line): RuntimeEvent => JsonlCodec::decodeEvent($line), $lines);

        $seqs = array_map(static fn (RuntimeEvent $e): int => $e->seq, $decoded);
        $this->assertContains(148, $seqs);
        $this->assertContains(151, $seqs);
        $this->assertContains(155, $seqs);
        $this->assertContains(159, $seqs);
    }

    public function testDrainRegistersCursorOnRunResumedAndForwardsCanonicalEvents(): void
    {
        $runId = 'resumed-session-7';
        $client = new FlakySeqDrainAgentSessionClient(
            throwOnCall: 0,
            eventsByCall: [
                1 => [
                    new RuntimeEvent(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $runId, 5, ['tool_name' => 'bash']),
                ],
            ],
        );

        $emitter = new RuntimeEventEmitter($client, new RuntimeExceptionBoundary(new EventDispatcher()), $this->createStub(LoggerInterface::class));
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunResumed->value,
            runId: $runId,
            seq: 1,
            payload: ['status' => 'attached'],
        ));

        $emitter->drainRegisteredRunsOnce();

        $this->assertSame(1, $client->eventsCallCount);

        $stdout = $this->stdoutHandle($emitter);
        rewind($stdout);
        $raw = stream_get_contents($stdout) ?: '';
        $lines = array_values(array_filter(array_map('trim', explode("\n", $raw))));
        $decoded = array_map(static fn (string $line): RuntimeEvent => JsonlCodec::decodeEvent($line), $lines);

        $types = array_map(static fn (RuntimeEvent $e): string => $e->type, $decoded);
        $this->assertContains(RuntimeEventTypeEnum::RunResumed->value, $types);
        $this->assertContains(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $types);
    }

    public function testEmitRegistersChildRunFromSubagentProgressAndDrainForwardsChildEvents(): void
    {
        $parentRunId = 'parent-42';
        $childRunId = 'child-99';
        $client = new FlakySeqDrainAgentSessionClient(
            throwOnCall: 0,
            eventsByCall: [
                2 => [
                    new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, $childRunId, 3, []),
                ],
            ],
        );

        $emitter = new RuntimeEventEmitter($client, new RuntimeExceptionBoundary(new EventDispatcher()), $this->createStub(LoggerInterface::class));
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $parentRunId,
            seq: 1,
            payload: [],
        ));

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::ToolExecutionOutputDelta->value,
            runId: $parentRunId,
            seq: 2,
            payload: [
                'tool_call_id' => 'tc',
                'subagent_progress' => [
                    'mode' => 'single',
                    'agent_run_id' => $childRunId,
                    'artifact_id' => 'a1',
                    'agent_name' => 'scout',
                    'status' => 'running',
                    'task_summary' => 't',
                ],
            ],
        ));

        $emitter->drainRegisteredRunsOnce();

        $stdout = $this->stdoutHandle($emitter);
        rewind($stdout);
        $raw = stream_get_contents($stdout) ?: '';
        $this->assertStringContainsString($childRunId, $raw);
        $this->assertStringContainsString('turn.started', $raw);
    }

    public function testDrainUsesEventsAfterWhenClientIsCursorAware(): void
    {
        $runId = 'cursor-run-1';
        $client = new CursorAwareDrainAgentSessionClient(
            eventsAfterByCall: [
                1 => [
                    new RuntimeEvent(RuntimeEventTypeEnum::TurnStarted->value, $runId, 10, []),
                ],
                2 => [
                    new RuntimeEvent(RuntimeEventTypeEnum::TurnCompleted->value, $runId, 11, []),
                ],
            ],
        );

        $emitter = new RuntimeEventEmitter($client, new RuntimeExceptionBoundary(new EventDispatcher()), $this->createStub(LoggerInterface::class));
        $emitter->openStdout();
        $this->replaceStdoutWithMemory($emitter);

        $emitter->emit(new RuntimeEvent(
            type: RuntimeEventTypeEnum::RunStarted->value,
            runId: $runId,
            seq: 1,
            payload: [],
        ));

        $emitter->drainRegisteredRunsOnce();
        $this->assertSame(1, $client->eventsAfterCallCount);
        $this->assertSame(0, $client->eventsAfterArgs[0]['afterSeq']);
        $this->assertSame(0, $client->plainEventsCallCount);

        $emitter->drainRegisteredRunsOnce();
        $this->assertSame(2, $client->eventsAfterCallCount);
        $this->assertSame(10, $client->eventsAfterArgs[1]['afterSeq']);
        $this->assertSame(0, $client->plainEventsCallCount);
    }

    private function createEmitter(): RuntimeEventEmitter
    {
        $boundary = new RuntimeExceptionBoundary(new EventDispatcher());
        $logger = $this->createStub(LoggerInterface::class);

        return new RuntimeEventEmitter(
            eventClient: null,
            boundary: $boundary,
            logger: $logger,
        );
    }

    private function replaceStdoutWithMemory(RuntimeEventEmitter $emitter): void
    {
        $ref = new \ReflectionClass($emitter);
        $prop = $ref->getProperty('stdout');
        $memory = fopen('php://memory', 'w+b');
        $this->assertIsResource($memory);
        $prop->setValue($emitter, $memory);
    }

    /** @return resource */
    private function stdoutHandle(RuntimeEventEmitter $emitter): mixed
    {
        $ref = new \ReflectionClass($emitter);
        $prop = $ref->getProperty('stdout');
        $stdout = $prop->getValue($emitter);
        $this->assertIsResource($stdout);

        return $stdout;
    }
}

/**
 * @internal
 */
final class FlakySeqDrainAgentSessionClient implements AgentSessionClient
{
    public int $eventsCallCount = 0;

    /** @var array<int, list<RuntimeEvent>> */
    private array $eventsByCall;

    /**
     * @param array<int, list<RuntimeEvent>> $eventsByCall 1-based call index => events to yield
     */
    public function __construct(
        private readonly int $throwOnCall,
        array $eventsByCall,
    ) {
        $this->eventsByCall = $eventsByCall;
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \BadMethodCallException('not used');
    }

    public function attach(string $runId): RunHandle
    {
        throw new \BadMethodCallException('not used');
    }

    public function send(string $runId, UserCommand $command): void
    {
    }

    public function events(string $runId): iterable
    {
        ++$this->eventsCallCount;
        if ($this->eventsCallCount === $this->throwOnCall) {
            throw new \RuntimeException('transient event store read failure');
        }

        yield from $this->eventsByCall[$this->eventsCallCount] ?? [];
    }

    public function cancel(string $runId): void
    {
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \BadMethodCallException('not used');
    }

    public function completeRun(string $runId): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}

/**
 * @internal
 */
final class CursorAwareDrainAgentSessionClient implements AgentSessionClient, CursorAwareAgentSessionClientInterface
{
    public int $eventsAfterCallCount = 0;
    public int $plainEventsCallCount = 0;

    /** @var list<array{runId: string, afterSeq: int}> */
    public array $eventsAfterArgs = [];

    /** @var array<int, list<RuntimeEvent>> */
    private array $eventsAfterByCall;

    /**
     * @param array<int, list<RuntimeEvent>> $eventsAfterByCall 1-based call index => events
     */
    public function __construct(array $eventsAfterByCall)
    {
        $this->eventsAfterByCall = $eventsAfterByCall;
    }

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \BadMethodCallException('not used');
    }

    public function attach(string $runId): RunHandle
    {
        throw new \BadMethodCallException('not used');
    }

    public function send(string $runId, UserCommand $command): void
    {
    }

    public function events(string $runId): iterable
    {
        ++$this->plainEventsCallCount;
        throw new \BadMethodCallException('plain events() must not be used when cursor-aware drain is active');
    }

    public function eventsAfter(string $runId, int $afterSeq): iterable
    {
        ++$this->eventsAfterCallCount;
        $this->eventsAfterArgs[] = ['runId' => $runId, 'afterSeq' => $afterSeq];

        yield from $this->eventsAfterByCall[$this->eventsAfterCallCount] ?? [];
    }

    public function cancel(string $runId): void
    {
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \BadMethodCallException('not used');
    }

    public function completeRun(string $runId): void
    {
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
    }
}
