<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\AgentCore\Contract\RunOperationalStatusReaderInterface;
use Ineersa\AgentCore\Contract\Tool\DeferredToolCompletionRepositoryInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Tool\DeferredToolCompletionCorrelation;
use Ineersa\AgentCore\Tests\Support\TestMessageBus;
use Ineersa\AgentCore\Tests\Support\TestToolBatchRegistration;
use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\BackgroundProcessRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\Support\TestProcessRuntime;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeDiagnosticsToolResultProcessor;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeReplyTransportFactory;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalIdentity;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalReportHandler;
use Ineersa\CodingAgent\Tool\CodeMode\DTO\CodeModeTerminalReportDTO;
use Ineersa\CodingAgent\Tool\OutputCap;
use Ineersa\CodingAgent\Tool\OutputCapToolResultProcessor;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeTerminalReportHandler */
final class CodeModeTerminalReportHandlerTest extends IsolatedKernelTestCase
{
    public function testDispatchesRetainedCanonicalResultWithFinalDiagnosticsAfterExit(): void
    {
        $root = TestDirectoryIsolation::createProjectTempDir('code-mode-terminal');
        try {
            $container = self::getContainer();
            $runId = $container->get(HatfieldSessionStore::class)->createSession('canonical terminal report');
            $call = new DeferredToolCompletionCorrelation('invocation', $runId, 3, 'agent-step', 2, 'agent-key', 'agent-call', 'code_mode', ['script' => 'return 42;'], 4);
            $deferred = $container->get(DeferredToolCompletionRepositoryInterface::class);
            TestToolBatchRegistration::registerDeferredInvocation($container->get(ToolBatchStoreInterface::class), $call);
            $deferred->registerPending($call);
            $store = $container->get(ProcessStore::class);
            $owner = new CodeModeProcessOwner(
                new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $root), new NullLogger(), new LockFactory(new FlockStore($root)), TestProcessRuntime::source()),
                $container->get(BackgroundProcessRepository::class),
                $store,
            );
            $workspace = $root.'/workspace';
            $store->insertRecord(['session_id' => $runId, 'code_mode_id' => $call->deferredId, 'code_mode_launch' => json_encode([
                'workspace' => $workspace, 'startedAtNs' => hrtime(true), 'standaloneTurn' => null,
                'policy' => ['explicitTimeout' => null, 'callerTimeout' => null, 'retainOutput' => false, 'attachmentOwner' => $call->toolCallId],
            ], \JSON_THROW_ON_ERROR)]);
            $payloads = $container->get(ToolPayloadStoreInterface::class);
            $raw = CodeModeTerminalIdentity::forInvocation($runId, $call->deferredId);
            $payloads->publishFirstResult(new ToolCallResult(
                $runId, $raw->turnNo, $raw->stepId, $raw->attempt, $raw->idempotencyKey, $raw->toolCallId, $raw->orderIndex,
                ['ok' => true, 'result' => 42],
            ), $raw);
            $bus = new TestMessageBus();
            $handler = new CodeModeTerminalReportHandler(
                $owner, $deferred, $bus, $container->get(OutputCap::class), $payloads,
                $container->get(CodeModeDiagnosticsToolResultProcessor::class),
                $container->get(OutputCapToolResultProcessor::class),
                $container->get(CodeModeReplyTransportFactory::class),
                $container->get(RunOperationalStatusReaderInterface::class),
            );
            $report = new CodeModeTerminalReportDTO($runId, $call->deferredId);
            $handler($report);
            $this->assertSame([], $bus->messages, 'A retained value is not evidence of native exit.');

            // Model the wrapper's durable exit evidence after the final writes, without starting a process.
            $fs = new Filesystem();
            $fs->dumpFile($workspace.'/stdout.log', "shutdown output\n");
            $fs->dumpFile($workspace.'/stderr.log', "shutdown warning\n");
            $fs->dumpFile($root.'/code-mode-'.hash('sha256', $runId).'-'.hash('sha256', $call->deferredId).'.status', "0\n");
            $handler($report);

            $this->assertCount(1, $bus->messages);
            $message = $bus->messages[0];
            $this->assertInstanceOf(ToolCallResult::class, $message);
            $this->assertSame([$runId, 3, 'agent-step', 2, 'agent-key', 'agent-call', 4], [
                $message->runId(), $message->turnNo(), $message->stepId(), $message->attempt(),
                $message->idempotencyKey(), $message->toolCallId, $message->orderIndex,
            ]);
            $this->assertFalse($message->isError);
            $this->assertSame([
                'tool_name' => 'code_mode',
                'content' => [['type' => 'text', 'text' => "42\n\ncode_mode diagnostics\nstdout:\nshutdown output\n\nstderr:\nshutdown warning"]],
                'details' => ['raw_result' => 42],
                'tool_idempotency_key' => null,
                'mode' => null,
                'arguments' => ['script' => 'return 42;'],
            ], $message->result);
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }
}
