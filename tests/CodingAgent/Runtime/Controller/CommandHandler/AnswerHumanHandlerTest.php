<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\CommandHandler;

use Ineersa\CodingAgent\Runtime\Contract\AgentSessionClient;
use Ineersa\CodingAgent\Runtime\Contract\RunHandle;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Runtime\Contract\UserCommand;
use Ineersa\CodingAgent\Runtime\Controller\CommandHandler\AnswerHumanHandler;
use Ineersa\CodingAgent\Runtime\Controller\Event\ControllerCommandEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeCommand;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AnswerHumanHandler::class)]
final class AnswerHumanHandlerTest extends TestCase
{
    private SpySessionClient $spyClient;

    protected function setUp(): void
    {
        $this->spyClient = new SpySessionClient();
    }

    public function testDispatchesAnswerHumanToClient(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_1',
            type: 'answer_human',
            runId: 'run-123',
            payload: [
                'question_id' => 'sg_abc',
                'answer' => 'Allow once',
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        self::assertNotNull($this->spyClient->lastCommand);
        self::assertSame('answer_human', $this->spyClient->lastCommand->type);
        self::assertSame('sg_abc', $this->spyClient->lastCommand->payload['question_id'] ?? null);
        self::assertSame('Allow once', $this->spyClient->lastCommand->payload['answer'] ?? null);
    }

    public function testEmitsProtocolErrorWhenRunIdMissing(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_2',
            type: 'answer_human',
            runId: '',
            payload: ['question_id' => 'sg_abc'],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testEmitsProtocolErrorWhenQuestionIdMissing(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_3',
            type: 'answer_human',
            runId: 'run-456',
            payload: ['answer' => 'Deny'],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
        self::assertSame('run-456', $emittedEvents[0]->runId);
    }

    public function testIgnoresNonAnswerHumanCommands(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_4',
            type: 'user_message',
            runId: 'run-789',
            payload: ['text' => 'hello'],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        self::assertNull($this->spyClient->lastCommand);
        $this->addToAssertionCount(1);
    }

    public function testEmitsProtocolErrorWhenAnswerMissing(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_5',
            type: 'answer_human',
            runId: 'run-999',
            payload: ['question_id' => 'sg_def'],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        // Missing answer must be rejected with protocol.error — safety-critical
        // approvals must never pass null/missing through silently.
        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testEmitsProtocolErrorWhenAnswerEmpty(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_6',
            type: 'answer_human',
            runId: 'run-111',
            payload: ['question_id' => 'sg_ghi', 'answer' => ''],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    public function testEmitsProtocolErrorWhenAnswerNonScalar(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_7',
            type: 'answer_human',
            runId: 'run-222',
            payload: ['question_id' => 'sg_jkl', 'answer' => ['nested' => 'array']],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }

    // ── QH-06 follow-up: boolean false must pass the gate (regression guard) ──

    public function testDispatchesBooleanFalseAnswer(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_bool_false',
            type: 'answer_human',
            runId: 'run-bool',
            payload: [
                'question_id' => 'q_confirm',
                'answer' => false,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        // Boolean false must dispatch (confirm "No" answers). The gate at
        // line ~61 accepts is_scalar(false) and is not a string, so it must
        // NOT be rejected as ProtocolError. If a future refactor reverts the
        // gate to '' === (string) $answer, this test fails.
        self::assertNotNull($this->spyClient->lastCommand);
        self::assertSame('answer_human', $this->spyClient->lastCommand->type);
        self::assertSame('q_confirm', $this->spyClient->lastCommand->payload['question_id'] ?? null);
        self::assertFalse($this->spyClient->lastCommand->payload['answer'] ?? null);
    }

    public function testDispatchesBooleanTrueAnswer(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $command = new RuntimeCommand(
            id: 'cmd_bool_true',
            type: 'answer_human',
            runId: 'run-bool',
            payload: [
                'question_id' => 'q_confirm',
                'answer' => true,
            ],
        );

        $event = new ControllerCommandEvent($command, static function (): void {});
        $handler($event);

        // Boolean true must dispatch (confirm "Yes" answers).
        self::assertNotNull($this->spyClient->lastCommand);
        self::assertSame('answer_human', $this->spyClient->lastCommand->type);
        self::assertSame('q_confirm', $this->spyClient->lastCommand->payload['question_id'] ?? null);
        self::assertTrue($this->spyClient->lastCommand->payload['answer'] ?? null);
    }

    public function testEmitsProtocolErrorWhenAnswerIsExplicitNull(): void
    {
        $handler = new AnswerHumanHandler($this->spyClient);

        $emittedEvents = [];
        $emit = static function (RuntimeEvent $event) use (&$emittedEvents): void {
            $emittedEvents[] = $event;
        };

        $command = new RuntimeCommand(
            id: 'cmd_null',
            type: 'answer_human',
            runId: 'run-null',
            payload: [
                'question_id' => 'q_null',
                'answer' => null,
            ],
        );

        $event = new ControllerCommandEvent($command, $emit);
        $handler($event);

        // Explicit null is not scalar → must be rejected. Protects against
        // a future gate relaxing is_scalar without guarding null.
        self::assertNull($this->spyClient->lastCommand);
        self::assertCount(1, $emittedEvents);
        self::assertSame(RuntimeEventTypeEnum::ProtocolError->value, $emittedEvents[0]->type);
    }
}

/**
 * Test spy for AgentSessionClient::send().
 *
 * @internal test helper
 */
final class SpySessionClient implements AgentSessionClient
{
    public ?UserCommand $lastCommand = null;

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
        $this->lastCommand = $command;
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
