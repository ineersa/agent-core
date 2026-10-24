<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Doctrine\DBAL\Connection;
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
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTranslator;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use Ineersa\CodingAgent\Session\History\RunPresentationProjector;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CodeModeAcknowledgementHandlerTest extends IsolatedKernelTestCase
{
    public function testAcknowledgementColdReplayPreservesCallerHistoryWithoutExecutionEnd(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'return 42;');
        $id = $this->begin($f->call);
        $c->get(CodeModeExecutionService::class)->ready($id);
        $handler = $c->get(CodeModeAcknowledgementHandler::class);
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
        $runtimeAck = $c->get(RuntimeEventTranslator::class)->translate($ack->events[0]);
        $this->assertNotNull($runtimeAck);
        $this->assertSame(RuntimeEventTypeEnum::ToolCallAcknowledged->value, $runtimeAck->type);
        $this->assertNotSame(RuntimeEventTypeEnum::ToolExecutionCompleted->value, $runtimeAck->type);
        $this->assertTrue($runtimeAck->payload['backgrounded']);
        $this->assertStringContainsString('original host and worker remain occupied', $runtimeAck->payload['result']);
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
        $this->acceptAndComplete($id, $f->call, $result);
        $handler = self::getContainer()->get(CodeModeAcknowledgementHandler::class);
        $this->assertSame([], $handler->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state)->events);
        $events = self::getContainer()->get(PreparedTransitionEventStoreInterface::class)->allFor($f->state->runId);
        $this->assertContains('tool_execution_end', array_column($events, 'type'));
        $this->assertSame('completed', self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertNull(self::getContainer()->get(ToolBatchStoreInterface::class)->loadSchedule($f->state->runId, 1, 'step'));
    }

    public function testAcknowledgementRejectsCancelledStaleAndUnreadyOwners(): void
    {
        $f = new CodeModeBackendFixture(self::getContainer(), 'return 42;');
        $id = $this->begin($f->call);
        $handler = self::getContainer()->get(CodeModeAcknowledgementHandler::class);
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

    public function testMismatchedTerminalEnvelopeCannotReplaceFrozenEvidence(): void
    {
        $f = new CodeModeBackendFixture(self::getContainer(), 'return 42;');
        $id = $this->begin($f->call);
        self::getContainer()->get(CodeModeExecutionService::class)->ready($id);
        $payloads = self::getContainer()->get(ToolPayloadStoreInterface::class);
        $deferred = self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id)
            ?? throw new \RuntimeException('Missing invocation.');
        foreach (['turn', 'step', 'attempt', 'idempotency', 'order', 'name'] as $field) {
            $call = $f->call;
            $result = new ToolCallResult($call->runId(), 'turn' === $field ? 2 : 1, 'step' === $field ? 'other' : 'step', 'attempt' === $field ? 2 : 1, 'idempotency' === $field ? 'wrong' : $call->idempotencyKey(), 'cm', 'order' === $field ? 1 : 0, ['tool_name' => 'name' === $field ? 'bash' : 'code_mode', 'content' => [['type' => 'text', 'text' => '42']]]);
            try {
                $payloads->retainProcessedResult($invocation, $result);
                $this->fail('Mismatched '.$field.' was retained.');
            } catch (\Throwable $error) {
                $this->assertNotSame('', $error->getMessage());
            }
            $this->assertNull($deferred->findAcceptedResultReferenceByDeferredId($id));
        }
        $this->assertSame(36, \strlen($id));
        $first = $payloads->retainProcessedResult($invocation, $this->terminalResult($f->call));
        $deferred->acceptResult($id, $first);
        $deferred->acceptResult($id, $payloads->retainProcessedResult($invocation, $this->terminalResult($f->call, 'late replacement')));
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));
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
        $ack = $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($f->state->runId, $id, 'cm', true), $f->state);
        $db = $c->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_deferred_ack BEFORE UPDATE OF acknowledged_at ON tool_deferred_completion BEGIN SELECT RAISE(ABORT, 'fixture acknowledgement failure'); END");
        try {
            try {
                $f->commit($f->state, $ack);
                $this->fail('Acknowledgement unexpectedly succeeded.');
            } catch (\Symfony\Component\Messenger\Exception\HandlerFailedException $error) {
                $this->assertStringContainsString('fixture acknowledgement failure', $error->getMessage());
            }
            $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->acknowledgedAt($id));
            $this->assertSame(['cm' => true], $c->get(ToolBatchStoreInterface::class)->loadSchedule($f->state->runId, 1, 'step')->inFlight);
            $this->assertNotNull($c->get(PreparedTransitionEventStoreInterface::class)->verifiedPendingTransition($f->state->runId));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_deferred_ack');
        }
        $c->get(PendingTransitionRecovery::class)->recover($f->state->runId);
        $this->assertNotNull($c->get(DeferredToolCompletionRepositoryInterface::class)->acknowledgedAt($id));
        $this->assertSame('pending', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
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

    private function acceptAndComplete(string $id, ExecuteToolCall $call, ToolCallResult $result): void
    {
        $c = self::getContainer();
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id)
            ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $payloads->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $this->consumeDeferred(new CompleteDeferredToolCall(
            $id,
            \is_array($result->result['content'] ?? null) ? $result->result['content'] : [],
            \is_array($result->result['details'] ?? null) ? $result->result['details'] : null,
            $result->isError,
            $result->error,
        ));
    }

    private function consumeDeferred(CompleteDeferredToolCall $message): void
    {
        $c = self::getContainer();
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $bus = $c->get('agent.command.bus');
        $bus->dispatch($message);
        $this->drainRunControl($bus, $transport);
        // Async run_control finalizes the batch only after ToolCallResult
        // consumption. A settle retry then removes the retained settled batch.
        $bus->dispatch($message);
        $this->drainRunControl($bus, $transport);
    }

    private function drainRunControl(object $bus, object $transport): void
    {
        $seen = 0;
        for ($i = 0; $i < 16; ++$i) {
            $sent = $transport->getSent();
            if ($seen >= \count($sent)) {
                break;
            }
            for (; $seen < \count($sent); ++$seen) {
                $envelope = $sent[$seen];
                $payload = $envelope->getMessage();
                $this->assertFalse($payload instanceof CompleteDeferredToolCall, 'Inline deferred bodies cannot be received.');
                if ($payload instanceof ToolPayloadMessage || $payload instanceof ToolCallResult) {
                    $bus->dispatch($envelope->with(new ReceivedStamp('run_control')));
                }
            }
        }
    }
}
