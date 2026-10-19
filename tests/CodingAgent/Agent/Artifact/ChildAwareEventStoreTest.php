<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class ChildAwareEventStoreTest extends PerMethodIsolatedKernelTestCase
{
    #[\PHPUnit\Framework\Attributes\DataProvider('ownerAuditSizes')]
    public function testWholeOwnerTransitionAudit(int $messageCount, int $messageBytes, int $sample): void
    {
        $container = self::getContainer();
        $logger = new TestLogger();
        $container->set(\Ineersa\AgentCore\Application\Handler\RunTracer::class, new \Ineersa\AgentCore\Application\Handler\RunTracer($logger));
        // Exercise explicit compaction independently of automatic scheduling.
        $container->get(\Ineersa\CodingAgent\Config\AppConfig::class)->compaction = new \Ineersa\CodingAgent\Config\CompactionConfig(autoEnabled: false, keepRecentTokens: 16);
        $bus = $container->get('agent.command.bus');
        $sessions = $container->get(HatfieldSessionStore::class);
        $active = $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class);
        $report = [];
        $last = function (string $transport, string $class, string $run) use ($container): \Symfony\Component\Messenger\Envelope {
            $matches = array_values(array_filter($container->get('messenger.transport.'.$transport)->getSent(), static fn ($envelope): bool => $envelope->getMessage() instanceof $class && $envelope->getMessage()->runId() === $run));
            $this->assertNotEmpty($matches, $class);

            return $matches[array_key_last($matches)];
        };
        $run = $sessions->createSession('isolated owner audit');
        $consume = static function (object $message, string $operation) use ($bus, $container, $logger, &$report, $sample): void {
            $queued = $message instanceof \Symfony\Component\Messenger\Envelope ? $message : $bus->dispatch($message);
            $logger->records = [];
            $started = hrtime(true);
            $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
            $container->get('messenger.transport.run_control')->ack($queued);
            $report[] = ['sample' => $sample, 'operation' => $operation, 'whole_owner_ms' => (hrtime(true) - $started) / 1e6, 'queue_wait_ms' => null,
                'spans' => array_values(array_filter($logger->records, static fn (array $record): bool => 'agent_loop.trace.finish' === $record['message']))];
        };
        $messages = [];
        for ($i = 0; $i < $messageCount; ++$i) {
            $messages[] = new \Ineersa\AgentCore\Domain\Message\AgentMessage(0 === $i % 2 ? 'user' : 'assistant', [['type' => 'text', 'text' => str_repeat('x', $messageBytes)]]);
        }
        $consume(new \Ineersa\AgentCore\Domain\Message\StartRun($run, 0, 'start', 1, 'start', new \Ineersa\AgentCore\Domain\Message\StartRunPayload('synthetic system', $messages,
            new \Ineersa\AgentCore\Domain\Run\RunMetadata(model: 'llama_cpp_test/test'))), 'start');
        $consume($last('run_control', \Ineersa\AgentCore\Domain\Message\AdvanceRun::class, $run), 'advance');
        $llm = $last('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class, $run)->getMessage();
        $consume(new \Ineersa\AgentCore\Domain\Message\LlmStepResult($run, $llm->turnNo(), $llm->stepId(), $llm->attempt(), $llm->idempotencyKey(),
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls([['id' => 'audit-read', 'name' => 'read', 'arguments' => ['path' => './synthetic.txt']]]),
            toolsRef: 'default', model: 'llama_cpp_test/test', stopReason: 'tool_call'), 'llm_tools');
        $tool = $last('tool', \Ineersa\AgentCore\Domain\Message\ExecuteToolCall::class, $run)->getMessage();
        $consume(new \Ineersa\AgentCore\Domain\Message\ToolCallResult($run, $tool->turnNo(), $tool->stepId(), $tool->attempt(), $tool->idempotencyKey(), $tool->toolCallId, 0,
            result: ['content' => [['type' => 'text', 'text' => str_repeat('r', $messageBytes)]]]), 'tool_result');
        $finish = static function () use ($last, $consume, $run, $messageBytes): void {
            $consume($last('run_control', \Ineersa\AgentCore\Domain\Message\AdvanceRun::class, $run), 'advance_after_input');
            $request = $last('llm', \Ineersa\AgentCore\Domain\Message\ExecuteLlmStep::class, $run)->getMessage();
            $consume(new \Ineersa\AgentCore\Domain\Message\LlmStepResult($run, $request->turnNo(), $request->stepId(), $request->attempt(), $request->idempotencyKey(),
                assistantMessage: SymfonyAiTestMessages::assistantText(str_repeat('a', $messageBytes)), model: 'llama_cpp_test/test', stopReason: 'stop'), 'llm_text');
        };
        $finish();
        $payload = ['message' => ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'synthetic follow-up']]]];
        $consume(new \Ineersa\AgentCore\Domain\Message\ApplyCommand($run, $active->requireLoaded($run)->turnNo, 'follow', 1, 'follow', 'follow_up', $payload), 'follow_up');
        $finish();
        $turn = $active->requireLoaded($run)->turnNo;
        $active->release($run);
        $consume(new \Ineersa\AgentCore\Domain\Message\ApplyCommand($run, $turn, 'cold', 1, 'cold', 'follow_up', $payload), 'cold_recovery_follow_up');
        $finish();
        $consume(new \Ineersa\AgentCore\Domain\Message\CompactRun($run, $active->requireLoaded($run)->turnNo, 'compact', 1, 'compact'), 'compact_prepare');
        $compaction = $last('llm', \Ineersa\AgentCore\Domain\Message\ExecuteCompactionStep::class, $run)->getMessage();
        $consume(new \Ineersa\AgentCore\Domain\Message\CompactionStepResult($run, $compaction->turnNo(), $compaction->stepId(), $compaction->attempt(), $compaction->idempotencyKey(),
            'synthetic summary', null, $compaction->retainedTailMessages, $compaction->messagesCompacted, $compaction->messagesRetained, $compaction->firstRetainedIndex,
            $compaction->tokenEstimateBefore, $compaction->trigger, model: $compaction->model), 'compact_result');
        $consume(new \Ineersa\AgentCore\Domain\Message\ApplyShellCommand($run, $active->requireLoaded($run)->turnNo, 'shell', 1, 'shell', '!true'), 'shell_accept');
        $shell = $last('tool', \Ineersa\AgentCore\Domain\Message\ExecuteShellToolCall::class, $run)->getMessage();
        $consume(new \Ineersa\AgentCore\Domain\Message\ToolCallResult($run, $shell->turnNo(), $shell->stepId(), $shell->attempt(), $shell->idempotencyKey(), $shell->toolCallId, 0,
            result: ['standalone' => $shell->standalone, 'content' => [['type' => 'text', 'text' => 'synthetic shell result']]]), 'shell_result');
        $this->assertSame(\Ineersa\AgentCore\Domain\Run\RunStatus::Completed, $active->requireLoaded($run)->status);
        if ('1' === getenv('HATFIELD_OWNER_AUDIT')) {
            $path = self::$kernel->getProjectDir().'/var/diagnostics/run-state-latency/audit-attributed-'.$messageCount.'x'.$messageBytes.'-'.$sample.'.json';
            file_put_contents($path, json_encode(['messages' => $messageCount, 'message_bytes' => $messageBytes, 'initial_text_bytes' => $messageCount * $messageBytes,
                'transport' => 'in-memory', 'external_execution' => 'not exercised', 'auto_compaction' => false, 'measurements' => $report], \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
        }
    }

    public static function ownerAuditSizes(): iterable
    {
        for ($sample = 0; $sample < 3; ++$sample) {
            yield 'routine '.$sample => [8, 128, $sample];
            yield 'larger history '.$sample => [64, 16384, $sample];
        }
    }

    public function testThreeReadyToolResultsUseOwnerPipeline(): void
    {
        $container = self::getContainer();
        $logger = new TestLogger();
        $container->set(\Ineersa\AgentCore\Application\Handler\RunTracer::class, new \Ineersa\AgentCore\Application\Handler\RunTracer($logger));
        $sessions = $container->get(HatfieldSessionStore::class);
        $artifacts = $container->get(AgentArtifactRegistry::class);
        $container->set(AgentChildRunDirectory::class, new AgentChildRunDirectory($sessions, $artifacts, $logger));
        $label = getenv('HATFIELD_LATENCY_LABEL');
        $parents = false === $label ? 3 : 80;
        for ($i = 0; $i < $parents; ++$i) {
            $parent = $sessions->createSession('synthetic catalog');
            for ($j = 0; $j < 3; ++$j) {
                $artifacts->create($parent, 'agent_'.$j, 'child-'.$i.'-'.$j, 'scout', AgentArtifactKindEnum::Subagent);
            }
        }
        $run = $sessions->createSession('synthetic owner');
        $store = $container->get(\Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface::class);
        PreparedEventStoreSeeder::appendMany($store, [
            RunEvent::forAppend($run, 0, 'run_started', ['payload' => ['metadata' => ['model' => 'llama_cpp_test/test', 'session' => []], 'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'synthetic request']]],
            ]]]),
            RunEvent::forAppend($run, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'tools', 'operation_attempt' => 1, 'operation_idempotency_key' => 'llm-key']),
        ]);
        $bus = $container->get('agent.command.bus');
        $calls = [];
        for ($i = 0; $i < 3; ++$i) {
            $calls[] = ['id' => 'read-'.$i, 'name' => 'read', 'arguments' => ['path' => './synthetic.txt']];
        }
        $queued = $bus->dispatch(new \Ineersa\AgentCore\Domain\Message\LlmStepResult($run, 1, 'tools', 1, 'llm-key',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls($calls), toolsRef: 'default', model: 'llama_cpp_test/test', stopReason: 'tool_call'));
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $container->get('messenger.transport.run_control')->ack($queued);
        $executions = $container->get('messenger.transport.tool')->getSent();
        $this->assertCount(3, $executions);
        $results = [];
        foreach ($executions as $execution) {
            $call = $execution->getMessage();
            $this->assertInstanceOf(\Ineersa\AgentCore\Domain\Message\ExecuteToolCall::class, $call);
            $results[] = $bus->dispatch(new \Ineersa\AgentCore\Domain\Message\ToolCallResult(
                $run, $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->orderIndex,
                result: ['content' => [['type' => 'text', 'text' => 'synthetic result']]],
            ));
        }
        // All results are ready before owner consumption. This measures FIFO
        // processing and waiting, not worker execution or a real parallel batch.
        $logger->records = [];
        $started = hrtime(true);
        $timings = [];
        foreach ($results as $index => $result) {
            $processing = hrtime(true);
            $bus->dispatch($result->with(new ReceivedStamp('run_control')));
            $container->get('messenger.transport.run_control')->ack($result);
            $timings[] = ['result' => $index, 'queue_wait_ms' => ($processing - $started) / 1e6, 'processing_ms' => (hrtime(true) - $processing) / 1e6, 'completion_ms' => (hrtime(true) - $started) / 1e6];
        }
        $state = $container->get(\Ineersa\AgentCore\Contract\ActiveRunContextInterface::class)->requireLoaded($run);
        $this->assertSame([], $state->pendingToolCalls);
        $this->assertCount(3, array_filter($state->messages, static fn ($message): bool => 'tool' === $message->role));
        if (false !== $label) {
            $this->assertContains($label, ['baseline', 'candidate']);
            $spans = array_values(array_filter($logger->records, static fn (array $record): bool => 'agent_loop.trace.finish' === $record['message']));
            $report = ['label' => $label, 'catalog_parents' => $parents, 'catalog_children' => $parents * 3, 'tool_execution_ms' => null, 'scope' => 'in-memory transport, three pre-enqueued results, real owner middleware/handler/commit; no execution workers or broker', 'results' => $timings, 'spans' => $spans];
            file_put_contents(self::$kernel->getProjectDir().'/var/diagnostics/run-state-latency/'.$label.'-synthetic.json', json_encode($report, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
        }
    }

    public function testKnownParentOperationsNeverScanChildRegistriesWithoutAProjection(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('reserved parent');
        $this->assertNull($container->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->find($run));
        $logger = new TestLogger();
        $store = $this->router($logger);
        $store->assertTransitionReady($run);
        $store->appendTransition([RunEvent::forAppend($run, 0, 'run_started', [])], [
            'run_id' => $run, 'predecessor_seq' => 0, 'effects' => [], 'actions' => [], 'post_commit_effects' => [],
        ]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertCount(1, $store->verifiedPendingBatch($run, $pending->identity));
        $store->finalizeVerifiedTransition($run, $pending->identity);
        $store->assertTransitionReady($run);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->assertSame($run, $store->firstFor($run)?->runId);
        $this->assertCount(1, iterator_to_array($store->rangeFor($run, 1, 1)));
        $this->assertCount(1, iterator_to_array($store->reverseFor($run)));
        $this->assertCount(1, $store->allFor($run));
        $this->assertSame($sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl', $store->historySource($run)->path);

        // A fresh router has no positive child cache or operational projection.
        // Its cold archive reads must still select the parent without discovery.
        $cold = $this->router($logger);
        $this->assertSame($run, $cold->firstFor($run)?->runId);
        $this->assertCount(1, $cold->allFor($run));
        $this->assertNull($container->get(\Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository::class)->find($run));
        $this->assertSame([], $logger->records, 'Global discovery emits a scan record before reading registries.');
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lateChildIdentities')]
    public function testChildReservedByAnotherDirectoryAfterAMissRemainsDiscoverable(string $child, bool $aliasParent = false): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $parent = $sessions->createSession('parent of later child');
        if ($aliasParent) {
            $child = '0'.$parent;
        }
        $logger = new TestLogger();
        $store = $this->router($logger);
        $this->assertSame([], $store->allFor($child));
        $this->assertCount(1, $logger->records, 'The unknown identity must still attempt discovery.');

        // The producer writes the durable reservation without registering it
        // with the already-running reader's in-memory directory.
        $container->get(AgentArtifactRegistry::class)->create($parent, 'agent_late', $child, 'scout', AgentArtifactKindEnum::Subagent);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 0, 'run_started', []));
        $this->assertCount(2, $logger->records, 'The earlier miss must not suppress discovery of a later reservation.');
        $this->assertSame($child, $store->firstFor($child)?->runId);
        $this->assertSame([], $store->allFor($parent));
        $this->assertFileExists($sessions->resolveSessionsBasePath().'/'.$parent.'/artifacts/agents/agent_late/events.jsonl');
        $this->assertSame($child, $this->router(new TestLogger())->firstFor($child)?->runId);
    }

    public static function lateChildIdentities(): iterable
    {
        yield 'opaque child' => ['late-child'];
        yield 'numeric unknown is not a known parent' => ['99999999'];
        yield 'leading-zero alias is not a canonical parent' => ['', true];
    }

    public function testAppendHandlesParentEvent(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $event = new RunEvent(
            runId: 'parent-ev-router',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: ['step_id' => 'test-step'],
        );

        // Should not throw.
        PreparedEventStoreSeeder::append($store, $event);

        $events = $store->allFor('parent-ev-router');
        $this->assertNotEmpty($events);
        $this->assertSame('parent-ev-router', $events[0]->runId);
    }

    public function testAllForReturnsEmptyForUnknownRunId(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $events = $store->allFor('nonexistent-ev-id');
        $this->assertSame([], $events);
    }

    public function testRangeForDelegatesParentEvents(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);
        PreparedEventStoreSeeder::append($store, new RunEvent(
            runId: 'parent-range-router',
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [],
        ));
        PreparedEventStoreSeeder::append($store, new RunEvent(
            runId: 'parent-range-router',
            seq: 2,
            turnNo: 1,
            type: RunEventTypeEnum::TurnAdvanced->value,
            payload: [],
        ));

        $events = iterator_to_array($store->rangeFor('parent-range-router', 2, 2));

        $this->assertSame([2], array_map(static fn (RunEvent $event): int => $event->seq, $events));
    }

    public function testAppendManyHandlesMultipleParentEvents(): void
    {
        $store = self::getContainer()->get(ChildAwareEventStore::class);

        $events = [
            new RunEvent(
                runId: 'parent-ev-many',
                seq: 1,
                turnNo: 0,
                type: RunEventTypeEnum::RunStarted->value,
                payload: [],
            ),
            new RunEvent(
                runId: 'parent-ev-many',
                seq: 2,
                turnNo: 1,
                type: RunEventTypeEnum::TurnAdvanced->value,
                payload: [],
            ),
        ];

        PreparedEventStoreSeeder::appendMany($store, $events);

        $results = $store->allFor('parent-ev-many');
        $this->assertCount(2, $results);
    }

    private function router(TestLogger $logger): ChildAwareEventStore
    {
        $container = self::getContainer();

        return new ChildAwareEventStore(
            $container->get(\Ineersa\CodingAgent\Session\SessionRunEventStore::class),
            $container->get(\Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory::class),
            new AgentChildRunDirectory($container->get(HatfieldSessionStore::class), $container->get(AgentArtifactRegistry::class), $logger),
        );
    }
}
