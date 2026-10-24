<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Application\Pipeline\PendingTransitionRecovery;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CodeModeAcknowledgementMessageHandlerTest extends IsolatedKernelTestCase
{
    public function testForegroundSettlementDeletesOriginalBatchAndDuplicateDeliveryIsANoOp(): void
    {
        [$fixture, $message] = $this->terminal();
        $this->deliver($message);
        $batches = self::getContainer()->get(ToolBatchStoreInterface::class);
        $this->assertNull($batches->loadSchedule($fixture->state->runId, 1, 'step'));
        $this->assertNull($batches->loadCallReference($fixture->state->runId, 1, 'step', 'cm'));
        $store = self::getContainer()->get(PreparedTransitionEventStoreInterface::class);
        $events = $store->allFor($fixture->state->runId);
        $this->assertSame(1, \count(array_filter($events, static fn ($event): bool => 'tool_execution_end' === $event->type)));
        $this->deliver($message);
        $this->assertEquals($events, $store->allFor($fixture->state->runId));
    }

    public function testDuplicateDeliveryRetriesAnInitiallyGuardedBatchDeletion(): void
    {
        [$fixture, $message] = $this->terminal();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement('CREATE TRIGGER retain_deferred_batch AFTER UPDATE OF status ON tool_deferred_completion WHEN NEW.status = \'completed\' BEGIN UPDATE tool_batch SET finalized = 0 WHERE run_id = NEW.run_id; END');
        try {
            // First consumption marks completed while the trigger forces
            // finalized=0, so deleteSettled keeps the batch. Do not settle-retry yet.
            $this->deliver($message, retrySettle: false);
            $this->assertNotNull(self::getContainer()->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
            $this->assertSame('completed', self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->status($message->deferredId));
        } finally {
            $db->executeStatement('DROP TRIGGER retain_deferred_batch');
        }
        $db->update('tool_batch', ['finalized' => 1], ['run_id' => $fixture->state->runId]);
        $this->deliver($message);
        $this->assertNull(self::getContainer()->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
    }

    public function testFailedMetadataSettlementRetainsBodiesUntilOwnerRecovery(): void
    {
        [$fixture, $message] = $this->terminal();
        $db = self::getContainer()->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_code_mode_settlement BEFORE UPDATE OF status ON tool_deferred_completion BEGIN SELECT RAISE(ABORT, 'fixture settlement failure'); END");
        try {
            try {
                $this->deliver($message);
                $this->fail('Settlement unexpectedly committed.');
            } catch (\Throwable $error) {
                $this->assertStringContainsString('fixture settlement failure', $error->getMessage());
            }
            $this->assertSame('pending', self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->status($message->deferredId));
            $this->assertNotNull(self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->findAcceptedResultReferenceByDeferredId($message->deferredId));
            $this->assertEquals($fixture->call, self::getContainer()->get(ToolBatchStoreInterface::class)->loadCall($fixture->state->runId, 1, 'step', 'cm'));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_code_mode_settlement');
        }
        // Aborted owner finalization closes Doctrine and leaves the staged journal
        // with a warm registry ahead of readable history. Production owner receive
        // recovers before admission; this producer retry must do the same.
        self::getContainer()->get(ManagerRegistry::class)->resetManager();
        self::getContainer()->get(PendingTransitionRecovery::class)->recover($fixture->state->runId);
        $this->deliver($message);
        $this->assertNull(self::getContainer()->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
    }

    /** @return array{CodeModeBackendFixture, CompleteDeferredToolCall} */
    private function terminal(): array
    {
        $c = self::getContainer();
        $fixture = new CodeModeBackendFixture($c, 'return 42;');
        $run = $fixture->state->runId;
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => $c->get(CodeModeExecutionService::class)->begin());
        $c->get(CodeModeExecutionService::class)->ready($id);
        $c->get(CodeModeExecutionService::class)->preserve($id, 42, null);
        $result = new ToolCallResult($run, 1, 'step', 1, $fixture->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => '42']]]);
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $payloads->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $message = new CompleteDeferredToolCall($id, [['type' => 'text', 'text' => '42']]);

        return [$fixture, $message];
    }

    private function deliver(CompleteDeferredToolCall $message, bool $retrySettle = true): void
    {
        $c = self::getContainer();
        $transport = $c->get('messenger.transport.run_control');
        $transport->reset();
        $bus = $c->get('agent.command.bus');
        // Producer seals the accepted body onto in-memory://; drain the sealed
        // ToolPayloadMessage with ReceivedStamp so owner admission actually runs.
        $bus->dispatch($message);
        $this->drainRunControl($bus, $transport);
        if ($retrySettle) {
            // Nested ToolCallResult finalizes the batch asynchronously. Redispatch
            // so middleware retries settle/delete after finalized=1, matching the
            // production duplicate-completion cleanup path.
            $bus->dispatch($message);
            $this->drainRunControl($bus, $transport);
        }
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
