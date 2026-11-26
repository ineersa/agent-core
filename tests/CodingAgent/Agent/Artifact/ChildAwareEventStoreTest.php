<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Artifact;

use Ineersa\AgentCore\Contract\ActiveRunContextInterface;
use Ineersa\AgentCore\Contract\PreparedTransitionEventStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Message\ToolPayloadMessage;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\AgentCore\Tests\Support\SymfonyAiTestMessages;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactKindEnum;
use Ineersa\CodingAgent\Agent\Artifact\AgentArtifactRegistry;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunDirectory;
use Ineersa\CodingAgent\Agent\Artifact\AgentChildRunEventStoreFactory;
use Ineersa\CodingAgent\Agent\Artifact\ChildAwareEventStore;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Repository\RunOperationalProjectionRepository;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\ChildRunFixture;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

final class ChildAwareEventStoreTest extends IsolatedKernelTestCase
{
    public function testThreeReadyToolResultsUseOwnerPipeline(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $artifacts = $container->get(AgentArtifactRegistry::class);
        for ($i = 0; $i < 3; ++$i) {
            $parent = $sessions->createSession('synthetic catalog');
            for ($j = 0; $j < 3; ++$j) {
                $artifacts->create($parent, 'agent_'.$j, 'child-'.$i.'-'.$j, 'scout', AgentArtifactKindEnum::Subagent);
            }
        }
        $run = $sessions->createSession('synthetic owner');
        $store = $container->get(PreparedTransitionEventStoreInterface::class);
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
        $queued = $bus->dispatch(new LlmStepResult($run, 1, 'tools', 1, 'llm-key',
            assistantMessage: SymfonyAiTestMessages::assistantWithToolCalls($calls), toolsRef: 'default', model: 'llama_cpp_test/test', stopReason: 'tool_call'));
        $bus->dispatch($queued->with(new ReceivedStamp('run_control')));
        $container->get('messenger.transport.run_control')->ack($queued);
        $executions = $container->get('messenger.transport.tool')->getSent();
        $this->assertCount(3, $executions);
        $results = [];
        $payloads = $container->get(ToolPayloadStoreInterface::class)->forRun($run);
        foreach ($executions as $execution) {
            $message = $execution->getMessage();
            $this->assertInstanceOf(ToolPayloadMessage::class, $message);
            $call = $payloads->read($message->reference);
            $this->assertInstanceOf(ExecuteToolCall::class, $call);
            $results[] = $bus->dispatch(new ToolCallResult(
                $run, $call->turnNo(), $call->stepId(), $call->attempt(), $call->idempotencyKey(), $call->toolCallId, $call->orderIndex,
                result: ['content' => [['type' => 'text', 'text' => 'synthetic result']]],
            ));
        }
        // All results are ready before consumption; no execution workers are involved.
        foreach ($results as $result) {
            $bus->dispatch($result->with(new ReceivedStamp('run_control')));
            $container->get('messenger.transport.run_control')->ack($result);
        }
        $active = $container->get(ActiveRunContextInterface::class);
        $state = $active->requireLoaded($run);
        $active->release($run);
        $this->assertSame([], $state->pendingToolCalls);
        $this->assertCount(3, array_filter($state->messages, static fn ($message): bool => 'tool' === $message->role));
    }

    public function testKnownParentOperationsNeverScanChildRegistriesWithoutAProjection(): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $run = $sessions->createSession('reserved parent');
        $this->assertNull($container->get(RunOperationalProjectionRepository::class)->find($run));
        $store = $this->router();
        $store->assertTransitionReady($run);
        $store->appendTransition([RunEvent::forAppend($run, 0, 'run_started', [])], [
            'run_id' => $run, 'predecessor_seq' => 0, 'effects' => [], 'actions' => [], 'post_commit_effects' => [],
        ]);
        $pending = $store->verifiedPendingTransition($run);
        $this->assertNotNull($pending);
        $this->assertCount(1, $store->verifiedPendingBatch($run, $pending));
        $store->finalizeVerifiedTransition($run, $pending);
        $store->assertTransitionReady($run);
        $this->assertSame(1, $store->latestSequenceFor($run));
        $this->assertSame($run, $store->firstFor($run)?->runId);
        $this->assertCount(1, iterator_to_array($store->rangeFor($run, 1, 1)));
        $this->assertCount(1, iterator_to_array($store->reverseFor($run)));
        $this->assertCount(1, $store->allFor($run));
        $this->assertSame($sessions->resolveSessionsBasePath().'/'.$run.'/events.jsonl', $store->historySource($run)->path);

        // A fresh router has no positive child cache or operational projection.
        // Its cold archive reads must still select the parent without discovery.
        $cold = $this->router();
        $this->assertSame($run, $cold->firstFor($run)?->runId);
        $this->assertCount(1, $cold->allFor($run));
        $this->assertNull($container->get(RunOperationalProjectionRepository::class)->find($run));
    }

    #[DataProvider('lateChildIdentities')]
    public function testChildReservedByAnotherDirectoryAfterAMissRemainsDiscoverable(string $child, bool $aliasParent = false): void
    {
        $container = self::getContainer();
        $sessions = $container->get(HatfieldSessionStore::class);
        $parent = $sessions->createSession('parent of later child');
        if ($aliasParent) {
            $child = '0'.$parent;
        }
        $store = $this->router();
        $this->assertSame([], $store->allFor($child));

        // The producer reserves the child in its batch, then writes the artifact,
        // without registering it with the already-running reader's directory.
        ChildRunFixture::create($container, $parent, 'agent_late', $child, 'scout', AgentArtifactKindEnum::Subagent);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($child, 0, 'run_started', []));
        $this->assertSame($child, $store->firstFor($child)?->runId, 'The earlier miss must not suppress a later reservation.');
        $this->assertSame([], $store->allFor($parent));
        $this->assertFileExists($sessions->resolveSessionsBasePath().'/'.$parent.'/artifacts/agents/agent_late/events.jsonl');
        $this->assertSame($child, $this->router()->firstFor($child)?->runId);
    }

    public static function lateChildIdentities(): iterable
    {
        yield 'opaque child' => ['late-child'];
        yield 'numeric unknown is not a known parent' => ['99999999'];
        yield 'leading-zero alias is not a canonical parent' => ['', true];
    }

    private function router(): ChildAwareEventStore
    {
        $container = self::getContainer();

        return new ChildAwareEventStore(
            $container->get(SessionRunEventStore::class),
            $container->get(AgentChildRunEventStoreFactory::class),
            new AgentChildRunDirectory($container->get(DeferredSubagentChildRepository::class), $container->get(AgentArtifactRegistry::class)),
        );
    }
}
