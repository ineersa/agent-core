<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Handler;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Tool\ToolBatchStoreInterface;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\ExecuteToolCall;
use Ineersa\AgentCore\Domain\Tool\ToolBatchStateDTO;
use Ineersa\AgentCore\Domain\Tool\ToolCallHumanInputAnswerDTO;
use Ineersa\AgentCore\Domain\Tool\ToolLaunchContextDTO;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallBuilder;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Group;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult as SymfonyToolResult;
use Symfony\AI\Platform\Result\ToolCall as SymfonyToolCall;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Transport\Serialization\Serializer as MessengerSerializer;
use Symfony\Component\Serializer\Normalizer\AbstractNormalizer;
use Symfony\Component\Serializer\SerializerInterface;

/**
 * Configured Messenger + durable SessionToolBatchStore boundary for ToolLaunchContextDTO.
 *
 * Proves fork nested AgentMessage fields, mixed ordinary-null batches, and HITL
 * answer copies survive the real container serializer and filesystem store.
 */
#[Group('db')]
final class ToolLaunchContextConfiguredBoundaryTest extends IsolatedKernelTestCase
{
    public function testConfiguredMessengerAndDurableStorePreserveForkNestedMessagesAndOrdinaryNull(): void
    {
        $hatfield = self::getContainer()->get(HatfieldSessionStore::class);
        \assert($hatfield instanceof HatfieldSessionStore);
        $runId = $hatfield->createSession('tool-launch-context-boundary');

        $inputStore = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolLaunchInputStoreInterface::class);
        $launchContext = $inputStore->publish(
            kind: ToolLaunchContextDTO::KIND_FORK,
            runId: $runId,
            turnNo: 4,
            stepId: 'step-fork',
            toolCallId: 'fork-1',
            model: 'openai-codex/gpt-5.6-sol',
            agentsContext: 'AGENTS.md body',
            messages: [
                new AgentMessage(
                    role: 'user-context',
                    content: [['type' => 'text', 'text' => 'AGENTS.md body']],
                    metadata: ['source' => 'agents_context'],
                ),
                new AgentMessage(
                    role: 'user',
                    content: [['type' => 'text', 'text' => 'do the work']],
                ),
            ],
        );
        $forkCall = new ExecuteToolCall(
            runId: $runId,
            turnNo: 4,
            stepId: 'step-fork',
            attempt: 1,
            idempotencyKey: 'fork-ik',
            toolCallId: 'fork-1',
            toolName: 'fork',
            args: ['task' => 'delegated'],
            orderIndex: 0,
            parentModel: 'openai-codex/gpt-5.6-sol',
            launchContext: $launchContext,
        )->withHumanInputAnswer(new ToolCallHumanInputAnswerDTO(
            questionId: 'q-fork',
            answer: ['approved' => true],
            continuationRef: [
                'run_id' => $runId,
                'turn_no' => 4,
                'step_id' => 'step-fork',
                'tool_call_id' => 'fork-1',
            ],
            requestPayload: ['hook' => 'safe_guard'],
        ));
        $ordinary = new ExecuteToolCall(
            runId: $runId,
            turnNo: 4,
            stepId: 'step-fork',
            attempt: 1,
            idempotencyKey: 'bash-ik',
            toolCallId: 'bash-1',
            toolName: 'bash',
            args: ['command' => 'ls'],
            orderIndex: 1,
            parentModel: 'openai-codex/gpt-5.6-sol',
        );
        $batch = new ToolBatchStateDTO(
            expectedOrder: ['fork-1' => 0, 'bash-1' => 1],
            calls: ['fork-1' => $forkCall, 'bash-1' => $ordinary],
            pendingQueue: ['fork-1'],
            inFlight: [],
            results: [],
            finalized: false,
            maxParallelism: 2,
            awaitingHumanInput: ['fork-1' => 'q-fork'],
        );

        /** @var SerializerInterface $serializer */
        $serializer = self::getContainer()->get(SerializerInterface::class);
        // llm/tool transports use FrameworkBundle's Symfony Messenger serializer
        // backed by the same configured SerializerInterface as SessionToolBatchStore.
        $messengerSerializer = new MessengerSerializer($serializer, 'json');
        $encoded = $messengerSerializer->encode(new Envelope($forkCall));
        $decoded = $messengerSerializer->decode($encoded)->getMessage();
        $this->assertInstanceOf(ExecuteToolCall::class, $decoded);
        $this->assertNotNull($decoded->launchContext);
        $this->assertSame('fork', $decoded->launchContext->kind);
        $this->assertSame($runId, $decoded->launchContext->producingRunId);
        $this->assertSame(4, $decoded->launchContext->producingTurnNo);
        $this->assertSame('openai-codex/gpt-5.6-sol', $decoded->launchContext->producingModel);
        $resolved = $inputStore->read($decoded->launchContext);
        $this->assertCount(2, $resolved->forkMessages);
        $this->assertSame('AGENTS.md body', $resolved->forkMessages[0]->content[0]['text']);
        $this->assertSame('do the work', $resolved->forkMessages[1]->content[0]['text']);
        $this->assertSame('q-fork', $decoded->humanInputAnswer?->questionId);

        /** @var ToolBatchStoreInterface $store */
        $store = self::getContainer()->get(ToolBatchStoreInterface::class);
        $store->save($runId, 4, 'step-fork', $batch);
        $loaded = $store->load($runId, 4, 'step-fork');
        $this->assertNotNull($loaded);
        $restoredFork = $loaded->calls['fork-1'];
        $this->assertNotNull($restoredFork->launchContext);
        $this->assertSame('fork', $restoredFork->launchContext->kind);
        $this->assertSame($runId, $restoredFork->launchContext->producingRunId);
        $this->assertSame(4, $restoredFork->launchContext->producingTurnNo);
        $this->assertSame('openai-codex/gpt-5.6-sol', $restoredFork->launchContext->producingModel);
        $restoredInput = $inputStore->read($restoredFork->launchContext);
        $this->assertCount(2, $restoredInput->forkMessages);
        $this->assertSame('AGENTS.md body', $restoredInput->forkMessages[0]->content[0]['text']);
        $this->assertSame('q-fork', $restoredFork->humanInputAnswer?->questionId);
        $this->assertNull($loaded->calls['bash-1']->launchContext);

        $this->assertSame(
            $serializer->normalize($batch, null, [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]),
            $serializer->normalize($loaded, null, [AbstractNormalizer::GROUPS => [ToolBatchStateDTO::SNAPSHOT_GROUP]]),
        );
    }

    public function testMismatchedLaunchContextFailsClosedBeforeToolboxExecution(): void
    {
        $accessor = new StackToolExecutionContextAccessor();
        $toolbox = new class implements ToolboxInterface {
            public bool $executed = false;

            public function getTools(): array
            {
                return [];
            }

            public function execute(SymfonyToolCall $toolCall): SymfonyToolResult
            {
                $this->executed = true;

                return new SymfonyToolResult($toolCall, 'should-not-run', new SourceCollection());
            }
        };
        $executor = new ToolExecutor(
            defaultMode: 'parallel',
            maxParallelism: 4,
            toolbox: $toolbox,
            resultStore: new ToolExecutionResultStore(),
            contextAccessor: $accessor,
        );

        $result = $executor->execute(ToolCallBuilder::create('fork-mismatch')
            ->withToolName('fork')
            ->withArguments(['task' => 'x'])
            ->withOrderIndex(0)
            ->withRunId('run-a')
            ->withContext([
                'turn_no' => 2,
                'parent_model' => 'model-a',
                'cancel_token' => new class implements CancellationTokenInterface {
                    public function isCancellationRequested(): bool
                    {
                        return false;
                    }
                },
                'launch_context' => new ToolLaunchContextDTO(
                    kind: ToolLaunchContextDTO::KIND_FORK,
                    producingRunId: 'run-b',
                    producingTurnNo: 2,
                    producingModel: 'model-a',
                ),
            ])
            ->build());

        $this->assertFalse($toolbox->executed);
        $this->assertTrue($result->isError);
        $this->assertSame(ToolCallException::class, $result->details['error_type'] ?? null);
        $this->assertStringContainsString('does not match tool envelope run', $result->content[0]['text'] ?? '');
        $this->assertNull($accessor->current());
    }
}
