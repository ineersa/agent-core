<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Application\Deferred;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Ineersa\AgentCore\Application\Handler\ExecuteToolCallWorker;
use Ineersa\AgentCore\Application\Handler\RunLockManager;
use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Pipeline\RunCommit;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\CommandStoreInterface;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolExecutorInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolResultProcessorInterface;
use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Command\PendingCommand;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\CompleteDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\RedriveDeferredToolCall;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Domain\Tool\DTO\ToolPayloadReferenceDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolResult;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionDispositionEnum;
use Ineersa\CodingAgent\Application\BackgroundCompletion\BackgroundCompletionOriginEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeInterruptionReasonEnum;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Application\CodeMode\DTO\CodeModeOwnerDTO;
use Ineersa\CodingAgent\Application\Deferred\DeferredCallerAcknowledgementService;
use Ineersa\CodingAgent\Application\Message\DTO\DeliverBackgroundCompletionDTO;
use Ineersa\CodingAgent\Application\Message\RepairSession;
use Ineersa\CodingAgent\Entity\BackgroundCompletionReceiptRepository;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Runtime\Contract\SessionRepairRefusalReasonEnum;
use Ineersa\CodingAgent\Runtime\Messenger\OwnerRunInitializationMiddleware;
use Ineersa\CodingAgent\Session\Contract\RunHistorySourceProviderInterface;
use Ineersa\CodingAgent\Session\Event\ControllerSessionShutdownEvent;
use Ineersa\CodingAgent\Session\Event\ControllerSessionStartingEvent;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryTailDiscardService;
use Ineersa\CodingAgent\Session\Repair\SessionRepairService;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeExecutionResult;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeHostBridge;
use Ineersa\CodingAgent\Tool\OutputCap;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as ToolboxResult;
use Symfony\AI\Platform\Result\ToolCall as ToolboxCall;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;

final class DeferredBackgroundCompletionSettlementTest extends PerMethodIsolatedKernelTestCase
{
    private InMemoryTransport $transport;
    private int $seen = 0;

    public static function interruptionBoundaries(): iterable
    {
        yield 'before admission' => [false];
        yield 'after admission before consumption' => [true];
    }

    #[DataProvider('interruptionBoundaries')]
    public function testInterruptedInvocationCannotContinueTheRunThroughQueuedNotification(bool $admitted): void
    {
        [$fixture, $id] = $this->acknowledged();
        $c = self::getContainer();
        $run = $fixture->state->runId;
        $identity = $id;
        $this->publish($fixture, $id);
        $this->drain(notifications: $admitted);
        $c->get(CodeModeExecutionRepository::class)->interrupt($id, CodeModeInterruptionReasonEnum::Cancelled);
        $this->assertFalse($c->get(HistoryTailDiscardService::class)->isContextMutatingMessage($this->deliver($run, $identity, '42')));
        if (!$admitted) {
            $this->dispatch($this->deliver($run, $identity, '42'));
        } else {
            $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
            $this->dispatch(new AdvanceRun($run, $state->turnNo, 'retire-notice', 1, 'retire-notice'));
        }
        $this->drain(advances: true);
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->assertNotContains('agent_command_applied', array_column($events, 'type'));
        $this->assertSame([], $c->get(CommandStoreInterface::class)->pending($run));
        $receipt = $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity);
        if ($admitted) {
            $this->assertSame(BackgroundCompletionDispositionEnum::Consumed, $receipt?->disposition);
            $this->assertContains('agent_command_rejected', array_column($events, 'type'));
        } else {
            $this->assertNull($receipt);
        }
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
    }

    public static function publicationFailures(): iterable
    {
        foreach (['failed send', 'acceptance database'] as $failure) {
            yield $failure.' success' => [$failure, false, true];
            yield $failure.' error' => [$failure, true, true];
        }
        yield 'cold worker success' => ['failed send', false, false];
        yield 'cold worker error' => ['failed send', true, false];
        yield 'bounded host diagnostics and explicit repair' => ['failed send', false, true, true];
    }

    #[DataProvider('publicationFailures')]
    public function testProcessedEvidenceSurvivesFailureBeforeAcceptance(string $failure, bool $error, bool $repair, bool $host = false): void
    {
        $c = self::getContainer();
        $script = $host ? 'for ($i=0; $i<160; ++$i) { fwrite(STDOUT, str_repeat("O",8192)); fwrite(STDERR, str_repeat("E",8192)); } return ["answer"=>42,"nested"=>[false,null],"workspace"=>__DIR__];' : 'return 42;';
        $fixture = new CodeModeBackendFixture($c, $script, runMetadata: ['model' => 'model', 'reasoning' => 'medium', ...($host ? ['session' => ['kind' => 'root']] : [])]);
        $run = $fixture->state->runId;
        $execution = $c->get(CodeModeExecutionService::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $db = $c->get(Connection::class);
        $id = null;
        $runs = 0;
        $invoke = function (ToolboxCall $call) use ($execution, $db, $failure, $run, $host, $script, $c, &$runs, &$id): ToolboxResult {
            ++$runs;
            $id = $execution->begin();
            $this->assertNotNull($id);
            $execution->ready($id);
            $this->dispatch(new CodeModeOwnerDTO($run, $id, 'cm', true));
            $this->drain();
            $value = ['answer' => 42, 'nested' => [false, null]];
            $diagnostics = ['stdout' => 'OUT', 'stderr' => 'ERR'];
            if ($host) {
                $captured = $c->get(CodeModeHostBridge::class)->execute($script, executionId: $id);
                $this->assertInstanceOf(CodeModeExecutionResult::class, $captured);
                $value = $captured->result;
                $diagnostics = $captured->diagnostics;
            } else {
                $execution->preserve($id, new CodeModeExecutionResult($value, $diagnostics), null);
            }
            if ('acceptance database' === $failure) {
                $db->executeStatement("CREATE TRIGGER fail_terminal_acceptance BEFORE UPDATE OF accepted_result_reference_json ON tool_deferred_completion WHEN NEW.accepted_result_reference_json IS NOT NULL BEGIN SELECT RAISE(ABORT, 'terminal acceptance unavailable'); END");
            }

            return new ToolboxResult($call, ['code_mode_value' => $value, 'code_mode_diagnostics' => $diagnostics]);
        };
        $toolbox = new class($invoke) implements ToolboxInterface {
            public function __construct(private \Closure $invoke)
            {
            }

            public function getTools(): array
            {
                return [];
            }

            public function execute(ToolboxCall $call): ToolboxResult
            {
                return ($this->invoke)($call);
            }
        };
        $processor = new class($error) implements ToolResultProcessorInterface {
            public int $count = 0;

            public function __construct(private bool $error)
            {
            }

            public function process(ToolResult $result, ToolCall $call): ToolResult
            {
                ++$this->count;

                return new ToolResult($result->toolCallId, $result->toolName, $result->content, $result->details, $this->error);
            }
        };
        $results = new ToolExecutionResultStore();
        $executor = new ToolExecutor('sequential', 2, $results, $toolbox, $c->get(StackToolExecutionContextAccessor::class), toolResultProcessors: [new CodeModeDiagnosticsToolResultProcessor(), $processor]);
        $logger = new TestLogger();
        $bus = $c->get('agent.command.bus');
        if ('failed send' === $failure) {
            $bus = new class($bus) implements \Symfony\Component\Messenger\MessageBusInterface {
                public function __construct(private \Symfony\Component\Messenger\MessageBusInterface $inner)
                {
                }

                public function dispatch(object $message, array $stamps = []): Envelope
                {
                    if ($message instanceof CompleteDeferredToolCall || $message instanceof ToolCallResult) {
                        throw new \RuntimeException('terminal send unavailable');
                    }

                    return $this->inner->dispatch($message, $stamps);
                }
            };
        }
        $worker = new ExecuteToolCallWorker($bus, $logger, $executor, $deferred, $results, $c->get(RunOperationalStatusReaderInterface::class), $payloads);
        try {
            $result = $worker($fixture->call);
        } finally {
            if ('acceptance database' === $failure) {
                $db->executeStatement('DROP TRIGGER IF EXISTS fail_terminal_acceptance');
            }
        }
        $this->assertNotNull($result);
        $this->assertNotNull($id);
        $this->assertSame(1, $runs);
        $this->assertSame(1, $processor->count);
        $this->assertSame($error, $result->isError);
        $paths = [];
        $nativeReference = $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['raw_reference'];
        if ($host) {
            $rawValue = $result->result['details']['raw_result'];
            $this->assertSame(42, $rawValue['answer']);
            $this->assertSame([false, null], $rawValue['nested']);
            $this->assertDirectoryDoesNotExist($rawValue['workspace']);
            $this->assertSame(2, preg_match_all('/Saved full output: ([^\n]+)/', $result->result['content'][0]['text'], $matches));
            $paths = $matches[1];
            $this->assertLessThan(2048, \strlen($result->result['content'][0]['text']));
            foreach ($paths as $index => $path) {
                $this->assertSame(hash('sha256', str_repeat(0 === $index ? 'O' : 'E', 1310720)), hash_file('sha256', $path));
            }
            $this->assertCount(2, glob(\dirname($paths[0]).'/*.txt'), 'Sealing retained evidence must not capture either stream twice.');
        } else {
            $this->assertSame(['answer' => 42, 'nested' => [false, null]], $result->result['details']['raw_result']);
            $this->assertStringContainsString('OUT', $result->result['content'][0]['text']);
            $this->assertStringContainsString('ERR', $result->result['content'][0]['text']);
        }
        $this->assertContains('runtime.result_send_failed', array_column($logger->records, 'message'));
        $acceptedAfterWorker = $deferred->findAcceptedResultReferenceByDeferredId($id);
        if ('failed send' === $failure) {
            // Acceptance precedes fallible dispatch. A send failure leaves the
            // first accepted reference for repair without re-executing.
            $this->assertNotNull($acceptedAfterWorker);
        } else {
            $this->assertNull($acceptedAfterWorker);
        }
        $this->assertSame('pending', $deferred->status($id));
        $invocation = $deferred->findInvocationReferenceByDeferredId($id);
        $this->assertNotNull($invocation);
        $processed = $payloads->findProcessedResultReference($invocation);
        $this->assertNotNull($processed);
        $this->assertEquals($result, $payloads->read($processed));
        if (null !== $acceptedAfterWorker) {
            $this->assertEquals($processed, $acceptedAfterWorker);
        }
        $this->completeRoot($run);
        if ($host) {
            // Exercise the production lifecycle listeners, including OutputCap
            // cleanup, before explicit repair reads the retained terminal.
            $disposable = $c->get(OutputCap::class)->capture([str_repeat('D', 1310720)], $run)['output_cap']['saved_path'];
            $this->assertFileExists($disposable);
            $c->get(EventDispatcherInterface::class)->dispatch(new ControllerSessionShutdownEvent($run));
            $c->get(EventDispatcherInterface::class)->dispatch(new ControllerSessionStartingEvent($run));
            $this->assertFileDoesNotExist($disposable);
            foreach ($paths as $index => $path) {
                $this->assertStringContainsString('/code-mode-results/', $path);
                $this->assertSame(hash('sha256', str_repeat(0 === $index ? 'O' : 'E', 1310720)), hash_file('sha256', $path));
            }
        }
        $acceptedBeforeRepair = $deferred->findAcceptedResultReferenceByDeferredId($id);
        $sent = $this->transport->getSent();
        $preview = $c->get(SessionRepairService::class)->repair($run, false, 'preview-processed');
        $this->assertStringContainsString('Retained deferred', $preview->message);
        $this->assertEquals($sent, $this->transport->getSent());
        $context = $c->get(ActiveRunContextInterface::class);
        $state = $context->requireLoaded($run);
        $context->replaceCurrent($state->with(['isStreaming' => true]));
        $refused = $c->get(SessionRepairService::class)->repair($run, true, 'refuse-processed');
        $this->assertSame(SessionRepairRefusalReasonEnum::ActiveStreaming, $refused->refusalReason);
        $this->assertEquals($sent, $this->transport->getSent());
        $this->assertEquals($acceptedBeforeRepair, $deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->assertEquals($processed, $payloads->findProcessedResultReference($invocation));
        $context->replaceCurrent($state);

        // This worker has no remembered ToolResult or original result processors.
        $neverExecute = $this->createMock(ToolExecutorInterface::class);
        $neverExecute->expects($this->never())->method('execute');
        $cold = new ExecuteToolCallWorker($c->get('agent.command.bus'), new TestLogger(), $neverExecute, $deferred, new ToolExecutionResultStore(), $c->get(RunOperationalStatusReaderInterface::class), $payloads, eventDispatcher: $c->get(EventDispatcherInterface::class));
        if ($repair) {
            $this->dispatch(new RepairSession($run, true, 'repair-processed'));
        } else {
            $this->assertEquals($result, $cold($fixture->call));
        }
        $this->drain();
        $acceptedJson = $db->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]);
        $this->assertIsString($acceptedJson);
        $accepted = $c->get(SerializerInterface::class)->deserialize($acceptedJson, ToolPayloadReferenceDTO::class, 'json');
        $this->assertEquals($result, $payloads->read($accepted));
        $this->assertEquals($processed, $accepted, 'Repair and cold recovery reuse the first shared processed reference.');
        // Accepted shared evidence survives the initial send failure. Admission
        // may complete, stay pending for another repair, or retire without a
        // mailbox receipt when an interrupted host is cleaned up.
        $this->assertContains($deferred->status($id), ['pending', 'completed']);
        if (null !== $c->get(BackgroundCompletionReceiptRepository::class)->receipt($id)) {
            $this->assertSame('completed', $deferred->status($id));
        }
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
        // Batch cleanup retains deferred-owned processed/accepted evidence until
        // permanent session deletion. Completed status never reopens.
        $this->assertEquals($processed, $payloads->findProcessedResultReference($invocation));
        $this->assertEquals($result, $payloads->read($accepted));
        $before = $this->transport->getSent();
        $this->assertNull($cold($fixture->call));
        $this->assertEquals($before, $this->transport->getSent());
        $this->assertEquals($processed, $payloads->findProcessedResultReference($invocation), 'Completed redelivery must not recreate or replace retained processed evidence.');
        $this->assertSame(1, $runs);
        $this->assertSame(1, $processor->count);
        if ($host) {
            $this->assertSame($nativeReference, $c->get(CodeModeExecutionRepository::class)->invocation($run, 'cm')['raw_reference']);
            foreach ($paths as $index => $path) {
                $this->assertStringContainsString($path, $payloads->read($accepted)->result['content'][0]['text']);
                $this->assertSame(hash('sha256', str_repeat(0 === $index ? 'O' : 'E', 1310720)), hash_file('sha256', $path));
            }
            $this->assertCount(2, glob(\dirname($paths[0]).'/*.txt'));
            $artifacts = $c->get(CodeModeResultArtifactStore::class);
            $c->get(HatfieldSessionStore::class)->deleteSession($run);
            foreach ($paths as $path) {
                $this->assertFileDoesNotExist($path);
            }
            $this->expectException(ToolPayloadException::class);
            $artifacts->retainDiagnostic($run, 'cm', $paths[0]);
        }
    }

    public function testGenericDeferredRepairAcceptsTheRetainedReferenceWithoutNativeOwnership(): void
    {
        [$fixture, $id] = $this->acknowledged(native: false);
        $c = self::getContainer();
        $run = $fixture->state->runId;
        $payloads = $c->get(ToolPayloadStoreInterface::class)->forRun($run);
        $call = $fixture->call;
        $result = new ToolCallResult($run, 1, 'step', 1, $call->idempotencyKey(), 'cm', 0, ['tool_name' => 'subagent', 'content' => [['type' => 'text', 'text' => 'processed generic terminal']], 'details' => ['raw_result' => ['answer' => 42]]]);
        $retained = $payloads->retainProcessedResult($payloads->publish($call), $result);
        $this->assertNull($c->get(DeferredToolCompletionRepositoryInterface::class)->findAcceptedResultReferenceByDeferredId($id));
        $this->completeRoot($run);
        $this->dispatch(new RepairSession($run, true, 'repair-generic-processed'));
        $this->drain();
        $json = $c->get(Connection::class)->fetchOne('SELECT accepted_result_reference_json FROM tool_deferred_completion WHERE deferred_id = ?', [$id]);
        $this->assertIsString($json);
        $accepted = $c->get(SerializerInterface::class)->deserialize($json, ToolPayloadReferenceDTO::class, 'json');
        $this->assertEquals($retained, $accepted, 'Generic repair reuses the processed reference without a native storage namespace.');
        $receipt = $c->get(BackgroundCompletionReceiptRepository::class)->receipt($id);
        $this->assertNotNull($receipt);
        $this->assertSame('processed generic terminal', $receipt->text);
        $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
        $this->assertSame([], $c->get('messenger.transport.tool')->getSent());
    }

    public static function repairCases(): iterable
    {
        foreach (['failed send', 'missing receipt', 'mailbox refusal'] as $failure) {
            yield $failure => [$failure, false, true];
            yield $failure.' with idle root' => [$failure, true, true];
            yield $failure.' with generic child' => [$failure, true, false];
        }
    }

    #[DataProvider('repairCases')]
    public function testExplicitRepairRetainsFirstTerminalUntilReceiptAdmission(string $failure, bool $idle, bool $native): void
    {
        [$fixture, $id] = $this->acknowledged($native);
        $c = self::getContainer();
        $run = $fixture->state->runId;
        $identity = $id;
        if ($idle) {
            $this->completeRoot($run);
        }
        $mailbox = $c->get(CommandStoreInterface::class);
        if ('mailbox refusal' === $failure) {
            for ($i = 0; $i < 100; ++$i) {
                $mailbox->enqueue(new PendingCommand($run, CoreCommandKind::AppendMessage, 'full-'.$i));
            }
        }
        $this->transport->failCompletion = 'failed send' === $failure;
        $this->publish($fixture, $id, $native);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $this->drain(notifications: 'missing receipt' !== $failure);
        $first = $deferred->findAcceptedResultReferenceByDeferredId($id);
        $this->assertNotNull($first);
        $this->assertSame('failed send' === $failure ? 1 : 0, $this->transport->failedSends);
        $this->assertSame('pending', $deferred->status($id));
        $this->assertNull($c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity));
        $this->assertEquals($fixture->call, $c->get(ToolBatchStoreInterface::class)->loadCall($run, 1, 'step', 'cm'));

        // A competing terminal delivery cannot replace the first accepted body.
        $this->transport->failCompletion = false;
        $this->dispatch(new CompleteDeferredToolCall($id, [['type' => 'text', 'text' => 'late replacement']]));
        $this->drain(notifications: false);
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));

        $before = $this->transport->getSent();
        $preview = $c->get(SessionRepairService::class)->repair($run, false, 'preview');
        $this->assertNull($preview->refusalReason);
        $this->assertStringContainsString('Retained deferred', $preview->message);
        $this->assertEquals($before, $this->transport->getSent());
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->transport->failCompletion = false;
        if ('mailbox refusal' === $failure) {
            foreach ($mailbox->pendingKeys($run) as $key) {
                $mailbox->markApplied($run, $key);
            }
        }
        $this->dispatch(new RepairSession($run, true, 'repair'));
        $this->drain();
        $receipt = $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity);
        $this->assertNotNull($receipt);
        $this->assertStringStartsWith($native ? \sprintf("Code-mode result (run: %s, tool call: cm, invocation: %s):\n42", $run, $id) : '42', $receipt->text);
        $this->assertStringNotContainsString('late replacement', $receipt->text);
        $this->assertSame('completed', $deferred->status($id));
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($run, 1, 'step'));
        $this->assertSame([$identity], $mailbox->pendingKeys($run));
        $events = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->dispatch($this->deliver($run, $identity, 'replacement must not win', $native));
        $this->drain();
        $this->assertEquals($receipt, $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity));
        $this->assertEquals($events, $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run));
        $this->assertSame([], $c->get('messenger.transport.tool')->getSent(), 'Repair must not re-execute source tools or processors.');
        $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
        $this->dispatch(new AdvanceRun($run, $state->turnNo, 'consume-notice', 1, 'consume-notice'));
        $this->drain(advances: true);
        $this->assertSame(BackgroundCompletionDispositionEnum::Consumed, $c->get(BackgroundCompletionReceiptRepository::class)->receipt($identity)->disposition);
        $this->assertSame([], $mailbox->pendingKeys($run));
        $consumedEvents = $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run);
        $this->dispatch($this->deliver($run, $identity, 'replacement after consumption', $native));
        $this->drain();
        $this->assertEquals($consumedEvents, $c->get(PreparedTransitionEventStoreInterface::class)->allFor($run));
    }

    public static function repairRefusals(): iterable
    {
        yield 'streaming' => [true];
        yield 'duplicate canonical sequence' => [false];
    }

    #[DataProvider('repairRefusals')]
    public function testRefusedRepairDoesNotRedeliverOrMutateAcceptedTerminal(bool $streaming): void
    {
        [$fixture, $id] = $this->acknowledged();
        $this->publish($fixture, $id);
        $this->drain(notifications: false);
        $c = self::getContainer();
        $run = $fixture->state->runId;
        $path = $c->get(RunHistorySourceProviderInterface::class)->historySource($run)->path;
        if ($streaming) {
            $context = $c->get(ActiveRunContextInterface::class);
            $context->replaceCurrent($context->requireLoaded($run)->with(['isStreaming' => true]));
        } else {
            $line = explode("\n", file_get_contents($path), 2)[0];
            (new Filesystem())->appendToFile($path, $line."\n");
        }
        $archive = file_get_contents($path);
        $sent = $this->transport->getSent();
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $first = $deferred->findAcceptedResultReferenceByDeferredId($id);
        $result = $c->get(SessionRepairService::class)->repair($run, true, 'refused');
        $this->assertSame($streaming ? SessionRepairRefusalReasonEnum::ActiveStreaming : SessionRepairRefusalReasonEnum::DuplicateSequences, $result->refusalReason);
        $this->assertEquals($sent, $this->transport->getSent());
        $this->assertSame($archive, file_get_contents($path));
        $this->assertEquals($first, $deferred->findAcceptedResultReferenceByDeferredId($id));
        $this->assertSame('pending', $deferred->status($id));
        $this->assertNull($c->get(BackgroundCompletionReceiptRepository::class)->receipt($id));
    }

    public function testRollbackRetainsBodiesAndDuplicateAdmissionRetriesCleanup(): void
    {
        [$fixture, $id] = $this->acknowledged();
        $this->publish($fixture, $id);
        $this->drain(notifications: false);
        $c = self::getContainer();
        $db = $c->get(Connection::class);
        // Enqueue follows receipt and source-status metadata. Fail afterward to
        // prove that rollback cannot leave the immutable batch files deleted.
        $db->executeStatement("CREATE TRIGGER fail_receipt_settlement BEFORE INSERT ON run_command BEGIN SELECT RAISE(ABORT, 'receipt rollback'); END");
        try {
            $this->dispatch($this->deliver($fixture->state->runId, $id, '42'));
            try {
                $this->drain();
                $this->fail('Receipt metadata unexpectedly committed.');
            } catch (\Throwable $error) {
                $this->assertStringContainsString('receipt rollback', $error->getMessage());
            }
            $this->assertSame('pending', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
            $this->assertNull($c->get(BackgroundCompletionReceiptRepository::class)->receipt($id));
            $this->assertEquals($fixture->call, $c->get(ToolBatchStoreInterface::class)->loadCall($fixture->state->runId, 1, 'step', 'cm'));
        } finally {
            $db->executeStatement('DROP TRIGGER fail_receipt_settlement');
        }
        // Messenger resets the closed manager before redelivery. This fixture
        // consumes envelopes directly, so perform that same reset explicitly.
        $c->get(ManagerRegistry::class)->resetManager();
        $db->executeStatement("CREATE TRIGGER retain_receipt_batch AFTER UPDATE OF status ON tool_deferred_completion WHEN NEW.status = 'completed' BEGIN UPDATE tool_batch SET finalized = 0 WHERE run_id = NEW.run_id; END");
        try {
            $this->dispatch($this->deliver($fixture->state->runId, $id, '42'));
            $this->drain();
            $this->assertSame('completed', $c->get(DeferredToolCompletionRepositoryInterface::class)->status($id));
            $this->assertNotNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
        } finally {
            $db->executeStatement('DROP TRIGGER retain_receipt_batch');
        }
        $db->update('tool_batch', ['finalized' => 1], ['run_id' => $fixture->state->runId]);
        $this->dispatch($this->deliver($fixture->state->runId, $id, '42'));
        $this->drain();
        $this->assertNull($c->get(ToolBatchStoreInterface::class)->loadSchedule($fixture->state->runId, 1, 'step'));
    }

    protected function afterKernelBoot(): void
    {
        $this->transport = new class extends InMemoryTransport {
            public bool $failCompletion = false;
            public int $failedSends = 0;

            public function send(Envelope $envelope): Envelope
            {
                if ($this->failCompletion && $envelope->getMessage() instanceof DeliverBackgroundCompletionDTO) {
                    ++$this->failedSends;
                    throw new TransportException('Fixture transport send failure.');
                }

                return parent::send($envelope);
            }
        };
        self::getContainer()->set('messenger.transport.run_control', $this->transport);
    }

    /** @return array{CodeModeBackendFixture, string} */
    private function acknowledged(bool $native = true): array
    {
        $c = self::getContainer();
        $fixture = new CodeModeBackendFixture($c, 'return 42;', $native ? 'code_mode' : 'subagent');
        $run = $fixture->state->runId;
        if (!$native) {
            $id = Uuid::v7()->toRfc4122();
            $call = $fixture->call;
            $c->get(DeferredToolCompletionRepositoryInterface::class)->registerPending(new DeferredToolCompletionCorrelation($id, $run, 1, 'step', 1, $call->idempotencyKey(), 'cm', $call->toolName, $call->args, 0, mode: 'sequential', timeoutSeconds: 8));
            $c->get(RunLockManager::class)->synchronized($run, static function () use ($c, $id, $fixture): void {
                $c->get(OwnerRunInitializationMiddleware::class)->initializeForOwner($fixture->state->runId, new RedriveDeferredToolCall($fixture->state->runId, $id));
                $result = $c->get(DeferredCallerAcknowledgementService::class)->acknowledge($id, $fixture->state, [['type' => 'text', 'text' => 'Child continues in background.']]);
                $c->get(RunCommit::class)->commit($fixture->state, $result->nextState, $result->events, postCommitActions: $result->postCommitActions, dispatchAfterTurnHooks: false);
            });

            return [$fixture, $id];
        }
        $service = $c->get(CodeModeExecutionService::class);
        $id = $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($run, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), $service->begin(...));
        $service->ready($id);
        $this->dispatch(new CodeModeOwnerDTO($run, $id, 'cm', true));
        $this->drain();
        $this->assertNotNull($c->get(DeferredToolCompletionRepositoryInterface::class)->acknowledgedAt($id));

        return [$fixture, $id];
    }

    private function publish(CodeModeBackendFixture $fixture, string $id, bool $native = true): void
    {
        if (!$native) {
            $this->dispatch(new CompleteDeferredToolCall($id, [['type' => 'text', 'text' => '42']]));

            return;
        }
        $c = self::getContainer();
        $c->get(CodeModeExecutionService::class)->preserve($id, 42, null);
        $result = new ToolCallResult($fixture->state->runId, 1, 'step', 1, $fixture->call->idempotencyKey(), 'cm', 0, ['tool_name' => 'code_mode', 'content' => [['type' => 'text', 'text' => '42']]]);
        $payloads = $c->get(ToolPayloadStoreInterface::class);
        $deferred = $c->get(DeferredToolCompletionRepositoryInterface::class);
        $invocation = $deferred->findInvocationReferenceByDeferredId($id) ?? throw new \RuntimeException('Missing invocation.');
        $accepted = $payloads->retainProcessedResult($invocation, $result);
        $deferred->acceptResult($id, $accepted);
        $this->dispatch(new CompleteDeferredToolCall($id, [['type' => 'text', 'text' => '42']]));
    }

    private function completeRoot(string $run): void
    {
        $c = self::getContainer();
        $c->get(RunLockManager::class)->synchronized($run, static function () use ($c, $run): void {
            $state = $c->get(ActiveRunContextInterface::class)->requireLoaded($run);
            $event = (new EventFactory())->event($run, $state->lastSeq + 1, $state->turnNo, 'agent_end', ['reason' => 'completed']);
            $c->get(RunCommit::class)->commit($state, $state->with(['status' => RunStatus::Completed]), [$event], dispatchAfterTurnHooks: false);
        });
    }

    private function origin(bool $native): BackgroundCompletionOriginEnum
    {
        return $native ? BackgroundCompletionOriginEnum::CodeMode : BackgroundCompletionOriginEnum::DeferredChild;
    }

    private function deliver(string $runId, string $identity, string $text, bool $native = true): DeliverBackgroundCompletionDTO
    {
        return new DeliverBackgroundCompletionDTO(
            $runId,
            $identity,
            $text,
            $this->origin($native),
            deferredId: $identity,
            sourceToolCallId: 'cm',
            sourceTurnNo: 1,
            sourceStepId: 'step',
            sourceAttempt: 1,
            sourceIdempotencyKey: 'original-execution',
        );
    }

    private function dispatch(object $message): void
    {
        self::getContainer()->get('agent.command.bus')->dispatch($message);
    }

    private function drain(bool $notifications = true, bool $advances = false): void
    {
        for ($round = 0; $round < 16; ++$round) {
            $sent = $this->transport->getSent();
            if ($this->seen >= \count($sent)) {
                return;
            }
            for (; $this->seen < \count($sent); ++$this->seen) {
                $envelope = $sent[$this->seen];
                $message = $envelope->getMessage();
                if ($message instanceof ToolPayloadMessage || $message instanceof CodeModeOwnerDTO || $message instanceof RepairSession || $message instanceof RedriveDeferredToolCall || ($notifications && $message instanceof DeliverBackgroundCompletionDTO) || ($advances && $message instanceof AdvanceRun)) {
                    self::getContainer()->get('agent.command.bus')->dispatch($envelope->with(new ReceivedStamp('run_control')));
                }
            }
        }
        $this->fail('Run-control fixture did not drain within its bounded message budget.');
    }
}
