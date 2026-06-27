<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\Tui\Command\SlashCommand;
use Ineersa\Tui\Command\TranscriptMessage;
use Ineersa\Tui\Listener\CompactCommandHandler;
use Ineersa\Tui\Runtime\TuiSessionState;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CompactCommandHandlerTest extends TestCase
{
    #[Test]
    public function returnsNoActiveSessionErrorWhenRunIdMissing(): void
    {
        $state = new TuiSessionState('compact-session');
        $handler = new CompactCommandHandler(new CompactCommandSpyClient(), $state);

        $result = $handler->handle(new SlashCommand('compact', '', '/compact'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('No active session to compact.', $result->text);
        $this->assertSame('error', $result->style);
    }

    #[Test]
    public function dispatchesCompactAndReturnsProgressMessage(): void
    {
        $client = new CompactCommandSpyClient();
        $state = new TuiSessionState('compact-session');
        $state->handle = new RunHandle('run-123');
        $handler = new CompactCommandHandler($client, $state);

        $result = $handler->handle(new SlashCommand('compact', 'Focus on key points.', '/compact Focus on key points.'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Compacting conversation...', $result->text);
        $this->assertSame('run-123', $client->lastCompactRunId);
        $this->assertSame('Focus on key points.', $client->lastCompactInstructions);
        $this->assertTrue($state->isCompacting);
    }

    #[Test]
    public function rejectsSecondCompactWhileInProgress(): void
    {
        $state = new TuiSessionState('compact-session');
        $state->handle = new RunHandle('run-123');
        $state->isCompacting = true;
        $handler = new CompactCommandHandler(new CompactCommandSpyClient(), $state);

        $result = $handler->handle(new SlashCommand('compact', '', '/compact'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertSame('Compaction already in progress.', $result->text);
        $this->assertSame('error', $result->style);
    }

    #[Test]
    public function resetsCompactingStateAndReturnsErrorWhenClientThrows(): void
    {
        $client = new CompactCommandSpyClient();
        $client->throwOnCompact = true;
        $state = new TuiSessionState('compact-session');
        $state->handle = new RunHandle('run-123');
        $handler = new CompactCommandHandler($client, $state);

        $result = $handler->handle(new SlashCommand('compact', '', '/compact'));

        $this->assertInstanceOf(TranscriptMessage::class, $result);
        $this->assertStringContainsString('Compaction failed:', $result->text);
        $this->assertSame('error', $result->style);
        $this->assertFalse($state->isCompacting);
    }
}

final class CompactCommandSpyClient implements AgentSessionClient
{
    public ?string $lastCompactRunId = null;
    public ?string $lastCompactInstructions = null;
    public bool $throwOnCompact = false;

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
        if ($this->throwOnCompact) {
            throw new \RuntimeException('transport unavailable');
        }

        $this->lastCompactRunId = $runId;
        $this->lastCompactInstructions = $customInstructions;
    }
}
