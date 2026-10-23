<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeOwnerHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeProcessedResultSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Session\History\RunPresentationProjector;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Serializer\SerializerInterface;

final class CodeModeOwnerHandlerTest extends IsolatedKernelTestCase
{
    public function testAcknowledgementColdReplayPreservesCallerHistoryWithoutExecutionEnd(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $this->begin($f->call);
        $c->get(CodeModeExecutionService::class)->ready($id);
        $handler = $c->get(CodeModeOwnerHandler::class);
        $message = new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true);
        $ack = $handler->handle($message, $f->state);
        $f->commit($f->state, $ack);
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($f->state->runId);
        $cold = $c->get(RunStateReducer::class)->replay($f->state, $events);
        $this->assertCount(2, $cold->messages);
        $this->assertSame('cm', $cold->messages[1]->toolCallId);
        $this->assertStringContainsString('original host and worker remain occupied', $cold->messages[1]->content[0]['text']);
        $this->assertSame([], $cold->pendingToolCalls);
        $this->assertSame($ack->nextState->activeStepId, $cold->activeStepId);
        $this->assertSame([], $handler->handle($message, $cold)->events);
        $this->assertNotContains('tool_execution_end', array_column($events, 'type'));
        $this->assertNull($c->get(RuntimeEventTranslator::class)->translate($ack->events[0]));
        $presentation = new RunPresentationProjector(new ReplayAssistantMessageFactory(), $c->get(ToolExecutionEndPayloadCodec::class), 100, 1000);
        foreach ($events as $event) {
            $presentation->observe($event);
        }
        $this->assertSame([], $presentation->snapshot()['pending']);
        $this->assertSame($cold->activeStepId, $presentation->snapshot()['activeStep']);
    }

    public function testCompletionBeforeAcknowledgementUsesOrdinaryForegroundResultOnce(): void
    {
        $f = new CodeModeBackendFixture(self::getContainer(), 'return 42;');
        $id = $this->begin($f->call);
        $service = self::getContainer()->get(CodeModeExecutionService::class);
        $service->ready($id);
        $service->preserve($id, 42, null);
        $result = $this->terminalResult($f->call);
        $event = new ProcessedToolResultDTO($result, $f->call);
        $bus = $this->publish($event);
        $this->assertTrue($event->handled);
        $this->assertCount(1, $bus->messages);
        $handler = self::getContainer()->get(CodeModeOwnerHandler::class);
        $this->assertSame([], $handler->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state)->events);
        $terminal = $handler->handle($bus->messages[0], $f->state);
        $this->assertSame(['tool_execution_end', 'tool_batch_committed'], array_column($terminal->events, 'type'));
        $this->assertSame('42', $terminal->nextState->messages[1]->content[0]['text']);
        $state = $f->commit($f->state, $terminal);
        $this->assertSame([], $handler->handle($bus->messages[0], $state)->events);
        $this->assertSame('completed', self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
    }

    public function testAcknowledgementRejectsCancelledStaleAndUnreadyOwners(): void
    {
        $f = new CodeModeBackendFixture(self::getContainer(), 'return 42;');
        $id = $this->begin($f->call);
        $handler = self::getContainer()->get(CodeModeOwnerHandler::class);
        $message = new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true);
        $this->assertSame([], $handler->handle($message, $f->state)->events);
        self::getContainer()->get(CodeModeExecutionService::class)->ready($id);
        foreach ([RunStatus::Cancelled, RunStatus::Cancelling, RunStatus::Failed, RunStatus::Completed] as $status) {
            $this->assertSame([], $handler->handle($message, $f->state->with(['status' => $status]))->events);
        }
        $this->assertSame([], $handler->handle($message, $f->state->with(['turnNo' => 2]))->events);
        $this->assertSame([], $handler->handle($message, $f->state->with(['activeStepId' => 'replacement']))->events);
        $this->assertSame([], $handler->handle(new CodeModeOwnerDTO($f->state->runId, 'wrong', 'cm', true), $f->state)->events);
        $this->assertSame([], $handler->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'other', true), $f->state)->events);
        $this->assertContains('tool_call_acknowledged', array_column($handler->handle($message, $f->state)->events, 'type'));
    }

    public function testMismatchedTerminalEnvelopeCannotInterceptOrReplaceFrozenEvidence(): void
    {
        $f = new CodeModeBackendFixture(self::getContainer(), 'return 42;');
        $id = $this->begin($f->call);
        foreach (['turn', 'step', 'attempt', 'idempotency', 'order', 'name', 'invocation'] as $field) {
            $call = $f->call;
            $result = new ToolCallResult($call->runId(), 'turn' === $field ? 2 : 1, 'step' === $field ? 'other' : 'step', 'attempt' === $field ? 2 : 1, 'idempotency' === $field ? 'wrong' : $call->idempotencyKey(), 'cm', 'order' === $field ? 1 : 0, ['tool_name' => 'name' === $field ? 'bash' : 'code_mode', 'content' => [['type' => 'text', 'text' => '42']]]);
            $original = 'invocation' === $field ? new ExecuteToolCall($call->runId(), 1, 'step', 1, $call->idempotencyKey(), 'cm', 'code_mode', ['script' => 'return 99;'], 0) : $call;
            $event = new ProcessedToolResultDTO($result, $original);
            try {
                $this->publish($event);
                $this->fail('Mismatched '.$field.' was intercepted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('frozen invocation', $error->getMessage());
            }
            $this->assertFalse($event->handled);
        }
        $row = self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $this->assertNull($row['result_reference']);
        $this->assertSame(36, \strlen($id));
        $this->assertFalse((bool) $row['settled']);
        $this->publish(new ProcessedToolResultDTO($this->terminalResult($f->call), $f->call));
        $row = self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $first = $row['result_reference'];
        $this->publish(new ProcessedToolResultDTO($this->terminalResult($f->call, 'late replacement'), $f->call));
        $this->assertSame($first, self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['result_reference']);
    }

    public function testNativeRegistrationRollsBackItsDeferredRowOnOwnershipFailure(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $db = $c->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_code_mode_registration BEFORE INSERT ON code_mode_execution BEGIN SELECT RAISE(ABORT, 'fixture registration failure'); END");
        try {
            try {
                $this->begin($f->call);
                $this->fail('Registration unexpectedly succeeded.');
            } catch (\Doctrine\DBAL\Exception $error) {
                $this->assertStringContainsString('fixture registration failure', $error->getMessage());
            }
            $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->statusForRunAndToolCall($f->state->runId, 'cm'));
            $this->assertNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm'));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_code_mode_registration');
        }
    }

    public function testCapturedDetachmentAndBatchCollectionRollBackAndRecoverTogether(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $this->begin($f->call);
        $c->get(CodeModeExecutionService::class)->ready($id);
        $ack = $c->get(CodeModeOwnerHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state);
        $db = $c->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_code_mode_detach BEFORE UPDATE OF detached ON code_mode_execution BEGIN SELECT RAISE(ABORT, 'fixture detachment failure'); END");
        try {
            try {
                $f->commit($f->state, $ack);
                $this->fail('Detachment unexpectedly succeeded.');
            } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $error) {
                $this->assertStringContainsString('fixture detachment failure', $error->getMessage());
            }
            $this->assertFalse((bool) $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['detached']);
            $this->assertSame(['cm' => true], $c->get(ToolBatchStoreInterface::class)->loadSchedule($f->state->runId, 1, 'step')->inFlight);
            $this->assertNotNull($c->get(PreparedTransitionEventStoreInterface::class)->verifiedPendingTransition($f->state->runId));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_code_mode_detach');
        }
        $c->get(PendingTransitionRecovery::class)->recover($f->state->runId);
        $this->assertTrue((bool) $c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['detached']);
        $this->assertSame([], $c->get(ToolBatchStoreInterface::class)->loadSchedule($f->state->runId, 1, 'step')->inFlight);
    }

    private function begin(ExecuteToolCall $call): string
    {
        return self::getContainer()->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($call->runId(), 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => self::getContainer()->get(CodeModeExecutionService::class)->begin());
    }

    private function terminalResult(ExecuteToolCall $call, string $text = '42'): ToolCallResult
    {
        return new ToolCallResult($call->runId(), 1, 'step', 1, $call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => $text]]]);
    }

    private function publish(ProcessedToolResultDTO $event): TestMessageBus
    {
        $c = self::getContainer();
        $bus = new TestMessageBus();
        (new CodeModeProcessedResultSubscriber($c->get(CodeModeExecutionRepository::class), $c->get(CodeModeResultArtifactStore::class), $c->get(RunLockManager::class), $c->get(SerializerInterface::class), $bus, $c->get(CodeModeExecutionService::class)))($event);

        return $bus;
    }
}
