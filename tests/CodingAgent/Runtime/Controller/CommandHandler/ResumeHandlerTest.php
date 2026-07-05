<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\ResumeHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: JSONL resume command must passive-attach only (no AgentCore Continue).
 */
#[CoversClass(ResumeHandler::class)]
final class ResumeHandlerTest extends TestCase
{
    public function testResumeJsonlCommandCallsAttachAndEmitsRunResumed(): void
    {
        $spy = new AttachSpySessionClient();
        $handler = new ResumeHandler($spy);

        $emitted = [];
        $emit = static function (RuntimeEvent $event) use (&$emitted): void {
            $emitted[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_resume_1',
            type: 'resume',
            runId: 'run-passive-attach',
        );

        $handler(new ControllerCommandEvent($command, $emit));

        $this->assertSame(['run-passive-attach'], $spy->attachRunIds);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::RunResumed->value, $emitted[0]->type);
        $this->assertSame('run-passive-attach', $emitted[0]->runId);
        $this->assertSame('attached', $emitted[0]->payload['status'] ?? null);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $spy = new AttachSpySessionClient();
        $handler = new ResumeHandler($spy);

        $emitted = [];
        $emit = static function (RuntimeEvent $event) use (&$emitted): void {
            $emitted[] = $event;
        };

        $command = new RuntimeCommand(id: 'cmd_2', type: 'resume', runId: '');
        $handler(new ControllerCommandEvent($command, $emit));

        $this->assertSame([], $spy->attachRunIds);
        $this->assertCount(1, $emitted);
        $this->assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emitted[0]->type);
    }
}

/**
 * @internal test helper
 */
final class AttachSpySessionClient implements AgentSessionClient
{
    /** @var list<string> */
    public array $attachRunIds = [];

    public function start(StartRunRequest $request): RunHandle
    {
        throw new \RuntimeException('Unexpected start()');
    }

    public function attach(string $runId): RunHandle
    {
        $this->attachRunIds[] = $runId;

        return new RunHandle(runId: $runId, status: 'attached');
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
        throw new \RuntimeException('Unexpected compact()');
    }
}
