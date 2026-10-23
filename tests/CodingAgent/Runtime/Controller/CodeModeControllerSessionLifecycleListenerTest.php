<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeAcknowledgementHandler;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Runtime\Controller\CodeModeControllerSessionLifecycleListener;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

final class CodeModeControllerSessionLifecycleListenerTest extends IsolatedKernelTestCase
{
    public function testColdProcessRecoversWaitingRootAndAcknowledgedOwnedChild(): void
    {
        // A second kernel in another process must recover committed evidence,
        // not live fixture objects. Cold schema/kernel boot uses a 15-second
        // safety cap; no product delay or time window synchronizes the test.
        $directory = TestDirectoryIsolation::createProjectTempDir('code-mode-cold-recovery');
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 2).'/Support/code-mode-controller-recovery-process.php', $directory, 'setup'], env: ['HATFIELD_SESSION_ID' => false]);
        try {
            $process->setTimeout(15);
            $process->mustRun();
            $lines = explode("\n", trim($process->getOutput()));
            $evidence = json_decode($lines[array_key_last($lines)], true, flags: \JSON_THROW_ON_ERROR);
            $this->assertCount(2, $evidence);
            foreach ($evidence as $row) {
                $this->assertSame('completed', $row['status']);
                $this->assertSame($row['root'] ? 1 : 0, $row['ends']);
                $this->assertSame($row['root'] ? 0 : 1, $row['acks']);
                $this->assertSame('controller_interrupted', $row['error']);
                $this->assertTrue($row['raw_exists']);
            }
        } finally {
            if ($process->isRunning()) {
                $process->stop(0);
            }
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testFailedRecoveryTransactionCannotLeaveAnAcceptanceOrFenceBehind(): void
    {
        $c = self::getContainer();
        $f = new CodeModeBackendFixture($c, 'throw new RuntimeException("Do not rerun.");');
        $service = $c->get(CodeModeExecutionService::class);
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($f->state->runId, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        $connection = $c->get(Connection::class);
        $connection->executeStatement("CREATE TEMP TRIGGER code_mode_recovery_abort BEFORE UPDATE OF interruption_reason ON code_mode_execution BEGIN SELECT RAISE(ABORT, 'controller recovery rollback'); END");
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        try {
            $listener->starting(new ControllerSessionStartingEvent($f->state->runId));
            $this->fail('Recovery transaction must fail.');
        } catch (DriverException $error) {
            $this->assertStringContainsString('controller recovery rollback', $error->getMessage());
        } finally {
            $connection->executeStatement('DROP TRIGGER code_mode_recovery_abort');
        }
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->findAcceptedResultReferenceByDeferredId($id));
        $this->assertNull($c->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm')['interruption_reason']);
        $listener->starting(new ControllerSessionStartingEvent($f->state->runId));
        $f->consumeCompletions();
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($f->state->runId);
        $this->assertCount(1, array_filter($events, static fn (RunEvent $e): bool => 'tool_execution_end' === $e->type));
    }

    /** @return iterable<string, array{bool, bool, bool, bool}> */
    public static function recoveryCases(): iterable
    {
        foreach ([false, true] as $child) {
            foreach ([false, true] as $acknowledged) {
                foreach ([false, true] as $processed) {
                    foreach ($acknowledged ? [true] : [false, true] as $ready) {
                        yield implode('-', [(int) $child, (int) $acknowledged, (int) $processed, (int) $ready]) => [$child, $acknowledged, $processed, $ready];
                    }
                }
            }
        }
    }

    #[DataProvider('recoveryCases')]
    public function testRecoveryUsesFirstEvidenceOrTruthfulFailureExactlyOnce(bool $child, bool $acknowledged, bool $processed, bool $ready): void
    {
        $c = self::getContainer();
        $owner = null;
        $run = null;
        if ($child) {
            $owner = $c->get(HatfieldSessionStore::class)->createSession('controller owner');
            $run = 'owned-child-'.$owner;
            $entry = $c->get(AgentArtifactRegistry::class)->create($owner, 'owned-child', $run, 'fork', AgentArtifactKindEnum::Fork);
            $c->get(AgentChildRunDirectory::class)->register($entry);
        }
        $f = new CodeModeBackendFixture($c, 'throw new \\RuntimeException("Execution must not be rerun.");', runId: $run);
        $owner ??= $f->state->runId;
        $run = $f->state->runId;
        $service = $c->get(CodeModeExecutionService::class);
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        if ($ready) {
            $service->ready($id);
        }
        if ($acknowledged) {
            // ACK must precede the host's terminal evidence, even when owner
            // acceptance and controller recovery happen later.
            $f->commit($f->state, $c->get(CodeModeAcknowledgementHandler::class)->handle(new CodeModeOwnerDTO($run, $id, 'cm', true), $f->state));
        }
        $service->preserve($id, ['value' => 42], null);
        $raw = $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['raw_reference'];
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $original = new ToolCallResult($run, 1, 'step', 1, $f->call->idempotencyKey(), 'cm', 0,
            ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => 'retained original']], 'details' => ['stdout' => 'OUT', 'stderr' => 'ERR']], true, ['type' => 'original_failure', 'message' => 'original failure', 'retryable' => false]);
        if ($processed) {
            $reference = $deferred->findInvocationReferenceByDeferredId($id);
            $c->get(ToolPayloadStoreInterface::class)->retainProcessedResult($reference, $original);
        }
        $listener = $c->get(CodeModeControllerSessionLifecycleListener::class);
        $event = new ControllerSessionStartingEvent($owner);
        $listener->starting($event);
        $acceptedJson = $c->get(Connection::class)->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]);
        $this->assertIsString($acceptedJson);
        $accepted = $service->resultReference($acceptedJson);
        $terminal = $c->get(CodeModeResultArtifactStore::class)->read($accepted);
        $this->assertTrue($terminal->isError);
        $this->assertSame($processed ? 'original_failure' : 'controller_interrupted', $terminal->error['type']);
        if ($processed) {
            $this->assertEquals($original, $terminal, 'Recovery must not reformat or reprocess the retained result.');
        } else {
            $this->assertStringContainsString('external effects may already have occurred', $terminal->error['message']);
            $this->assertFalse($terminal->error['retryable']);
        }
        $listener->starting($event); // A send may be queued but not admitted yet.
        $f->consumeCompletions();
        $this->assertSame('completed', $deferred->status($id));
        $listener->starting($event);
        $f->consumeCompletions();
        $this->assertSame($acceptedJson, $c->get(Connection::class)->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->assertCount($acknowledged ? 0 : 1, array_filter($events, static fn (RunEvent $e): bool => 'tool_execution_end' === $e->type));
        $this->assertCount($acknowledged ? 1 : 0, array_filter($events, static fn (RunEvent $e): bool => 'tool_call_acknowledged' === $e->type));
        $this->assertSame('controller_restarted', $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['interruption_reason']);
        $this->assertFileExists($c->get(CodeModeResultArtifactStore::class)->path($service->resultReference($raw)));
        $this->assertTrue($service->cancellationToken($id, new NullCancellationToken())->isCancellationRequested());
    }
}
