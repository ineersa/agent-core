<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Entity;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionStatusEnum;
use Ineersa\AgentCore\Contract\Tool\DeferredToolInterruptionReasonEnum;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\DTO\BackgroundCompletionReceiptDTO;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\BackgroundProcess;
use Ineersa\CodingAgent\Entity\DeferredSubagentBatch;
use Ineersa\CodingAgent\Entity\DeferredSubagentChild;
use Ineersa\CodingAgent\Entity\DeferredToolCompletion;
use Ineersa\CodingAgent\Kernel;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Tests\Support\SqlLoggingKernel;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BackgroundCompletionReceiptRepositoryTest extends IsolatedKernelTestCase
{
    public function testSourceQueriesSeekByOwnerInsteadOfScanningOtherSessions(): void
    {
        $container = self::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $repository = $container->get(BackgroundCompletionReceiptRepository::class);
        $logger = $container->get('test.sql_logger');
        // Retain both completed Bash history and pending code_mode from foreign
        // sessions. Assert the actual production query plan, never wall timing.
        for ($i = 0; $i < 200; ++$i) {
            $process = new BackgroundProcess();
            $process->sessionId = 'foreign-'.$i;
            $process->backgroundedAt = $process->finishedAt = $process->completionNotifiedAt = new \DateTimeImmutable();
            $invocation = new DeferredToolCompletion();
            $invocation->runId = $process->sessionId;
            $invocation->deferredId = 'foreign-invocation-'.$i;
            $invocation->toolName = 'code_mode';
            $invocation->backgroundedAt = new \DateTimeImmutable();
            $em->persist($process);
            $em->persist($invocation);
        }
        $em->flush();
        $logger->records = [];
        $this->assertFalse($repository->hasOutstandingWork('main'));
        $queries = array_values(array_filter($logger->records, static fn (array $row): bool => str_contains($row['context']['sql'] ?? '', 'SELECT EXISTS')));
        $this->assertCount(1, $queries);
        $query = $queries[0]['context'];
        $plan = $em->getConnection()->fetchAllAssociative('EXPLAIN QUERY PLAN '.$query['sql'], $query['params'] ?? []);
        $detail = implode("\n", array_column($plan, 'detail'));
        $this->assertStringContainsString('SEARCH process USING INDEX idx_bg_process_session_id (session_id=?)', $detail);
        $this->assertStringContainsString('SEARCH invocation USING INDEX idx_deferred_active_tool (tool_name=? AND status=? AND run_id=?)', $detail);
        foreach (['process', 'invocation', 'state', 'batch', 'child'] as $alias) {
            $this->assertStringNotContainsString('SCAN '.$alias, $detail);
        }
    }

    #[DataProvider('sources')]
    public function testSourcesRemainOutstandingThroughTerminalAndReceiptConsumption(string $kind, string $ownership): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(BackgroundCompletionReceiptRepository::class);
        $run = 'main';
        if ('main' !== $ownership) {
            $run = 'child';
            if ('operational' === $ownership) {
                self::getContainer()->get(RunOperationalProjectionRepository::class)->replace(new RunState($run, RunStatus::Running, parentRunId: 'main'));
            } else {
                $batch = new DeferredSubagentBatch();
                $batch->lifecycleId = 'reservation';
                $batch->parentRunId = 'main';
                $batch->parentToolCallId = 'reservation-tool';
                $child = new DeferredSubagentChild();
                $child->batchLifecycleId = $batch->lifecycleId;
                $child->childRunId = $run;
                $child->launchModel = 'provider/model';
                $child->launchReasoning = 'low';
                $em->persist($batch);
                $em->persist($child);
            }
        }
        $source = match ($kind) {
            'child' => new DeferredSubagentBatch(),
            'bash' => new BackgroundProcess(),
            'code_mode' => new DeferredToolCompletion(),
        };
        if ($source instanceof DeferredSubagentBatch) {
            $source->lifecycleId = 'background-child';
            $source->parentRunId = $run;
            $source->parentToolCallId = 'child-tool';
        } elseif ($source instanceof BackgroundProcess) {
            $source->sessionId = $run;
        } else {
            $source->deferredId = 'code-invocation';
            $source->runId = $run;
            $source->toolName = 'code_mode';
        }
        $em->persist($source);
        $em->flush();
        $this->assertFalse($repository->hasOutstandingWork('main'), 'Foreground work and reservations are not background waits.');
        $source->backgroundedAt = new \DateTimeImmutable();
        $em->flush();
        $this->assertTrue($repository->hasOutstandingWork('main'));
        $this->assertFalse($repository->hasOutstandingWork('unrelated'));

        // Terminal production and transport enqueue are not receipt admission.
        if ($source instanceof DeferredSubagentBatch) {
            $source->terminalCompletionEnqueuedAt = new \DateTimeImmutable();
            $identity = 'background-child-result-'.$source->lifecycleId;
        } elseif ($source instanceof BackgroundProcess) {
            $source->finishedAt = new \DateTimeImmutable();
            $identity = 'background-process-'.$source->id;
        } else {
            $source->acceptedResultReferenceJson = '{}';
            $identity = $source->deferredId;
        }
        $em->flush();
        $this->assertTrue($repository->hasOutstandingWork('main'), 'Do not wake main in the terminal-to-admission gap.');
        $repository->record(new BackgroundCompletionReceiptDTO($identity, $run, $run, $run, 'main', 'done', 'done', BackgroundCompletionDispositionEnum::Pending));
        if ($source instanceof BackgroundProcess) {
            $source->completionNotifiedAt = new \DateTimeImmutable();
        } elseif ($source instanceof DeferredToolCompletion) {
            $source->status = DeferredToolCompletionStatusEnum::Completed;
        }
        $em->flush();
        $this->assertTrue($repository->hasOutstandingWork('main'), 'Admitted but unconsumed notification still owns the wake-up.');
        $repository->consume($identity, $run, false);
        $this->assertFalse($repository->hasOutstandingWork('main'), 'Retained source history must not suppress goals forever.');
    }

    public static function sources(): iterable
    {
        yield 'fork or subagent batch' => ['child', 'main'];
        foreach (['main', 'operational', 'reservation'] as $ownership) {
            yield 'bash '.$ownership => ['bash', $ownership];
            yield 'code mode '.$ownership => ['code_mode', $ownership];
        }
    }

    public function testForwardingReceiptsBlockOnlyTheirOwningSessionUntilConsumed(): void
    {
        $repository = self::getContainer()->get(BackgroundCompletionReceiptRepository::class);
        $repository->record(new BackgroundCompletionReceiptDTO('forwarded', 'child', 'child', 'main', 'main', 'done', 'done', BackgroundCompletionDispositionEnum::Forwarding));
        $this->assertTrue($repository->hasOutstandingWork('main'));
        $this->assertFalse($repository->hasOutstandingWork('other'));
        $repository->record(new BackgroundCompletionReceiptDTO('forwarded', 'child', 'child', 'main', 'main', 'done', 'done', BackgroundCompletionDispositionEnum::Pending));
        $this->assertTrue($repository->hasOutstandingWork('main'));
        $repository->consume('forwarded', 'main', false);
        $this->assertFalse($repository->hasOutstandingWork('main'));
    }

    public function testAbandonedCodeModeDoesNotSuppressGoalContinuation(): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $repository = self::getContainer()->get(BackgroundCompletionReceiptRepository::class);
        $invocation = new DeferredToolCompletion();
        $invocation->deferredId = 'abandoned';
        $invocation->runId = 'main';
        $invocation->toolName = 'code_mode';
        $invocation->backgroundedAt = new \DateTimeImmutable();
        $em->persist($invocation);
        foreach ([DeferredToolInterruptionReasonEnum::Stopped, DeferredToolInterruptionReasonEnum::Cancelled, DeferredToolInterruptionReasonEnum::HistoryDiscarded] as $reason) {
            $invocation->interruptionReason = $reason;
            $em->flush();
            $this->assertFalse($repository->hasOutstandingWork('main'), $reason->value);
        }
    }

    /** @param array<array-key, mixed> $options */
    protected static function createKernel(array $options = []): Kernel
    {
        return new SqlLoggingKernel('test', false);
    }
}
