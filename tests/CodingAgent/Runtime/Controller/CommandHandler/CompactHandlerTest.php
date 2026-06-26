<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\CompactHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CompactHandler::class)]
final class CompactHandlerTest extends TestCase
{
    private CompactSpySessionClient $spyClient;

    protected function setUp(): void
    {
        $this->spyClient = new CompactSpySessionClient();
    }

    public function testDispatchesCompactToClient(): void
    {
        $handler = new CompactHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'compact',
            runId: 'run-123',
            payload: ['custom_instructions' => 'Focus on key points.'],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertSame('run-123', $this->spyClient->lastCompactRunId);
        $this->assertSame('Focus on key points.', $this->spyClient->lastCompactInstructions);
    }

    public function testDispatchesCompactWithoutCustomInstructions(): void
    {
        $handler = new CompactHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_2',
            type: 'compact',
            runId: 'run-456',
            payload: [],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertSame('run-456', $this->spyClient->lastCompactRunId);
        $this->assertNull($this->spyClient->lastCompactInstructions);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = new CompactHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_3',
            type: 'compact',
            runId: '',
            payload: [],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        $this->assertNull($this->spyClient->lastCompactRunId);
        $this->assertCount(1, $emittedEvents);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
        $this->assertStringContainsString('compact requires runId', $emittedEvents[0]->payload['error'] ?? '');
    }

    public function testIgnoresNonCompactCommands(): void
    {
        $handler = new CompactHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_4',
            type: 'user_message',
            runId: 'run-789',
            payload: ['text' => 'hello'],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        $this->assertNull($this->spyClient->lastCompactRunId);
        $this->addToAssertionCount(1);
    }
}

/**
 * Test spy for AgentSessionClient::compact().
 *
 * @internal test helper
 */
final class CompactSpySessionClient implements AgentSessionClient
{
    public ?string $lastCompactRunId = null;
    public ?string $lastCompactInstructions = null;

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('Unexpected start()');
    }

    public function resume(string $runId): RunHandle
    {
        throw new \RuntimeException('Unexpected resume()');
    }

    public function send(string $runId, UserCommand $command): void
    {
        throw new \RuntimeException('Unexpected send()');
    }

    public function events(string $runId): iterable
    {
        return [];
    }

    public function cancel(string $runId): void
    {
        throw new \RuntimeException('Unexpected cancel()');
    }

    public function shellExecute(string $command, string $sessionId, string $cwd): RunHandle
    {
        throw new \RuntimeException('Unexpected shellExecute()');
    }

    public function completeRun(string $runId): void
    {
        throw new \RuntimeException('Unexpected completeRun()');
    }

    public function compact(string $runId, ?string $customInstructions = null): void
    {
        $this->lastCompactRunId = $runId;
        $this->lastCompactInstructions = $customInstructions;
    }
}
