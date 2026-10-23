<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DTO\ProcessedToolResultDTO;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeProcessedResultSubscriber;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\HandlerFailedException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class CodeModeOwnerMessageHandlerTest extends IsolatedKernelTestCase
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
        // Hold the existing finalized guard closed without replacing deletion
        // with a test-side call. A repeated owner delivery must retry cleanup.
        $db->executeStatement('CREATE TRIGGER retain_code_mode_batch AFTER UPDATE OF settled ON code_mode_execution BEGIN UPDATE tool_batch SET finalized = 0 WHERE run_id = NEW.run_id; END');
        try {
            $this->deliver($message);
            $this->assertNotNull(self::getContainer()->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
            $this->assertSame('completed', self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->status($message->identity));
        } finally {
            $db->executeStatement('DROP TRIGGER retain_code_mode_batch');
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
            } catch (HandlerFailedException $error) {
                $this->assertStringContainsString('fixture settlement failure', $error->getMessage());
            }
            $this->assertSame('pending', self::getContainer()->get(DeferredToolCompletionRepositoryInterface::class)->status($message->identity));
            $this->assertFalse((bool) self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($fixture->state->runId, 'cm')['settled']);
            $this->assertEquals($fixture->call, self::getContainer()->get(ToolBatchStoreInterface::class)->loadCall($fixture->state->runId, 1, 'step', 'cm'));
            $this->assertNotNull(self::getContainer()->get(PreparedTransitionEventStoreInterface::class)->verifiedPendingTransition($fixture->state->runId));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_code_mode_settlement');
        }
        $this->deliver($message);
        $this->assertNull(self::getContainer()->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
    }

    /** @return array{CodeModeBackendFixture, CodeModeOwnerDTO} */
    private function terminal(): array
    {
        $c = self::getContainer();
        $fixture = new CodeModeBackendFixture($c, 'return 42;');
        $run = $fixture->state->runId;
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => $c->get(CodeModeExecutionService::class)->begin());
        $c->get(CodeModeExecutionService::class)->preserve($id, 42, null);
        $result = new ToolCallResult($run, 1, 'step', 1, $fixture->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => '42']]]);
        ($c->get(CodeModeProcessedResultSubscriber::class))(new ProcessedToolResultDTO($result, $fixture->call));

        return [$fixture, new CodeModeOwnerDTO($run, $id, 'cm')];
    }

    private function deliver(CodeModeOwnerDTO $message): void
    {
        self::getContainer()->get('agent.command.bus')->dispatch(new Envelope($message, [new ReceivedStamp('run_control')]));
    }
}
