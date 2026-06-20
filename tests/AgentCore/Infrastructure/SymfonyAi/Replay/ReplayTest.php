<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\Content\Thinking;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\Component\EventDispatcher\EventDispatcher;

/**
 * Automated replay tests that do NOT require live LLM.
 *
 * Exercises the full LlmPlatformAdapter path with fixture-driven
 * provider data.  Only the HTTP transport boundary is faked.
 *
 * MAINT-05C foundation: these tests must pass without llama.cpp.
 */
final class ReplayTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Replay a simple text-only fixture and verify the assistant message.
     */
    public function testReplayTextOnlyFixtureProducesCorrectAssistantMessage(): void
    {
        $fixture = $this->loadFixture('successful-response.json');

        $adapter = $this->buildAdapter($fixture);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: $fixture['model'],
            input: new ModelInvocationInput(
                runId: 'replay-text-run',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: array_map(
                    static fn (array $m): AgentMessage => new AgentMessage(
                        $m['role'],
                        [['type' => 'text', 'text' => $m['content']]],
                    ),
                    $fixture['input']['messages'],
                ),
            ),
        ));

        // Assistant message contains fixture text
        self::assertNotNull($result->assistantMessage);
        self::assertSame(
            $fixture['expected_text'],
            $result->assistantMessage->asText(),
        );

        // No tool calls in a text-only fixture
        self::assertFalse($result->assistantMessage->hasToolCalls());

        // Usage matches fixture
        self::assertSame($fixture['usage']['input_tokens'], $result->usage['input_tokens']);
        self::assertSame($fixture['usage']['output_tokens'], $result->usage['output_tokens']);
    }

    /**
     * Replay a fixture with tool call deltas and verify tool calls are assembled.
     */
    public function testReplayToolCallFixtureProducesCorrectToolCalls(): void
    {
        $fixture = $this->loadFixture('tool-call-response.json');

        $adapter = $this->buildAdapter($fixture);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: $fixture['model'],
            input: new ModelInvocationInput(
                runId: 'replay-tool-run',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: array_map(
                    static fn (array $m): AgentMessage => new AgentMessage(
                        $m['role'],
                        [['type' => 'text', 'text' => $m['content']]],
                    ),
                    $fixture['input']['messages'],
                ),
            ),
        ));

        self::assertNotNull($result->assistantMessage);

        // Tool call is present
        self::assertTrue(
            $result->assistantMessage->hasToolCalls(),
            'Assistant message should contain tool calls from fixture',
        );

        $toolCalls = $result->assistantMessage->getToolCalls();
        self::assertCount(1, $toolCalls);
        self::assertSame('call_abc123', $toolCalls[0]->getId());
        self::assertSame('read', $toolCalls[0]->getName());
        self::assertSame(
            ['path' => './notes.txt'],
            $toolCalls[0]->getArguments(),
        );

        // Stop reason reflects tool call
        self::assertSame('tool_call', $result->stopReason);

        // Text content is also present (model produced text after tool call)
        self::assertSame($fixture['expected_text'], $result->assistantMessage->asText());
    }

    /**
     * Replay a fixture with thinking deltas.
     */
    public function testReplayFixtureWithThinkingDeltas(): void
    {
        $fixture = $this->loadFixture('successful-response.json');

        // Add thinking deltas to test the thinking path
        $thinkingDeltas = [
            ['type' => 'thinking', 'content' => 'Let me explain recursion carefully.'],
            ['type' => 'thinking_signature', 'content' => 'sig_abc'],
        ];
        $fixture['deltas'] = array_merge($thinkingDeltas, $fixture['deltas']);

        $adapter = $this->buildAdapter($fixture);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: $fixture['model'],
            input: new ModelInvocationInput(
                runId: 'replay-think-run',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'Explain recursion']]),
                ],
            ),
        ));

        self::assertNotNull($result->assistantMessage);
        self::assertTrue(
            $result->assistantMessage->hasThinking(),
            'Assistant message should contain thinking content',
        );
    }

    /**
     * Replay with an empty delta list produces null assistant message.
     */
    public function testReplayEmptyDeltasProducesNullAssistantMessage(): void
    {
        $fixture = $this->loadFixture('successful-response.json');
        $fixture['deltas'] = [];

        $adapter = $this->buildAdapter($fixture);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: $fixture['model'],
            input: new ModelInvocationInput(
                runId: 'replay-empty-run',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'hi']]),
                ],
            ),
        ));

        self::assertNull($result->assistantMessage);
    }

    /**
     * Test that the StreamRecorderObserver correctly converts ToolCallStart
     * and ToolInputDelta deltas to fixture records.
     */
    public function testStreamRecorderObserverCapturesAllDeltaTypes(): void
    {
        $recorder = new StreamRecorderObserver();

        $recorder->onStreamStart('run-1', 'step-1');
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\TextDelta('Hello'));
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\ToolCallStart('call_1', 'read'));
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta('call_1', 'read', '{"path'));
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta('call_1', 'read', '":"./f'));
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\ToolInputDelta('call_1', 'read', 'ile.txt"}'));
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\ToolCallComplete([
            new \Symfony\AI\Platform\Result\ToolCall('call_1', 'read', ['path' => './file.txt']),
        ]));
        $recorder->onStreamEnd('run-1', 'step-1');

        $deltas = $recorder->getDeltas();
        self::assertCount(6, $deltas);

        self::assertSame('text', $deltas[0]['type']);
        self::assertSame('Hello', $deltas[0]['content']);

        self::assertSame('tool_call_start', $deltas[1]['type']);
        self::assertSame('call_1', $deltas[1]['id']);
        self::assertSame('read', $deltas[1]['name']);

        self::assertSame('tool_input_delta', $deltas[2]['type']);
        self::assertSame('{"path', $deltas[2]['partial_json']);

        self::assertSame('tool_call_complete', $deltas[5]['type']);
        self::assertSame('read', $deltas[5]['tool_calls'][0]['name']);

        // Verify buildFixture assembles correctly
        $fixture = $recorder->buildFixture([
            'model' => 'test/model',
            'input' => ['messages' => []],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
            'stop_reason' => 'tool_call',
        ]);
        self::assertSame('test/model', $fixture['model']);
        self::assertCount(6, $fixture['deltas']);
    }

    /**
     * Verify that writeFixture produces valid JSON.
     */
    public function testStreamRecorderObserverWriteFixtureProducesValidJson(): void
    {
        $recorder = new StreamRecorderObserver();
        $recorder->onStreamStart('run-1', 'step-1');
        $recorder->onDelta('run-1', 'step-1', new \Symfony\AI\Platform\Result\Stream\Delta\TextDelta('test'));
        $recorder->onStreamEnd('run-1', 'step-1');

        $tmpFile = sys_get_temp_dir().'/replay-test-fixture-'.uniqid('', true).'.json';
        try {
            $bytes = $recorder->writeFixture($tmpFile, [
                'model' => 'test/model',
                'input' => ['messages' => []],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
                'stop_reason' => 'stop',
            ]);
            self::assertGreaterThan(0, $bytes);

            // Round-trip: load it back
            $loaded = json_decode(file_get_contents($tmpFile), true);
            self::assertIsArray($loaded);
            self::assertSame('test/model', $loaded['model']);
            self::assertCount(1, $loaded['deltas']);
            self::assertSame('text', $loaded['deltas'][0]['type']);
        } finally {
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
        }
    }

    // ── resolveFixtureStopReason unit tests (no live LLM) ──────────

    public function testResolveFixtureStopReasonReturnsExplicitValueWhenProvided(): void
    {
        $result = StreamRecorderObserver::resolveFixtureStopReason(
            'tool_call',
            [['type' => 'text', 'content' => 'hello']],
        );
        self::assertSame('tool_call', $result);
    }

    public function testResolveFixtureStopReasonDetectsToolCallFromDeltas(): void
    {
        $result = StreamRecorderObserver::resolveFixtureStopReason(
            null,
            [
                ['type' => 'thinking', 'content' => '...'],
                ['type' => 'tool_call_start', 'id' => 'call_1', 'name' => 'read'],
                ['type' => 'tool_input_delta', 'id' => 'call_1', 'name' => 'read', 'partial_json' => '{}'],
                ['type' => 'tool_call_complete', 'tool_calls' => [['id' => 'call_1', 'name' => 'read', 'arguments' => []]]],
            ],
        );
        self::assertSame('tool_call', $result);
    }

    public function testResolveFixtureStopReasonReturnsStopForTextOnlyDeltas(): void
    {
        $result = StreamRecorderObserver::resolveFixtureStopReason(
            null,
            [['type' => 'text', 'content' => 'hello world']],
        );
        self::assertSame('stop', $result);
    }

    public function testResolveFixtureStopReasonReturnsStopForThinkingOnlyDeltas(): void
    {
        $result = StreamRecorderObserver::resolveFixtureStopReason(
            null,
            [
                ['type' => 'thinking', 'content' => 'Let me think...'],
                ['type' => 'thinking_signature', 'content' => 'sig123'],
            ],
        );
        self::assertSame('stop', $result);
    }

    public function testResolveFixtureStopReasonReturnsStopForEmptyDeltas(): void
    {
        $result = StreamRecorderObserver::resolveFixtureStopReason(null, []);
        self::assertSame('stop', $result);
    }

    public function testResolveFixtureStopReasonReturnsStopForMixedTextAndThinking(): void
    {
        $result = StreamRecorderObserver::resolveFixtureStopReason(
            null,
            [
                ['type' => 'thinking', 'content' => 'Hmm'],
                ['type' => 'text', 'content' => 'Hello'],
            ],
        );
        self::assertSame('stop', $result);
    }

    // ─── Helpers ───────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function loadFixture(string $name): array
    {
        $path = __DIR__.'/../../../Fixtures/traces/'.$name;
        self::assertFileExists($path, 'Fixture file not found: '.$path);

        $data = json_decode(file_get_contents($path), true);
        self::assertIsArray($data, 'Fixture must be valid JSON');

        return $data;
    }

    /**
     * Build a LlmPlatformAdapter wired with a fixture-replay platform.
     *
     * @param array<string, mixed> $fixture
     */
    private function buildAdapter(array $fixture): LlmPlatformAdapter
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: $fixture['input']['messages'][0]['role'] ?? 'replay-run',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [],
            activeStepId: null,
        ), 0);

        $modelClient = new FixtureReplayModelClient($fixture);
        $resultConverter = new FixtureReplayResultConverter($fixture);

        $eventDispatcher = new EventDispatcher();

        $platform = new Platform(
            providers: [new Provider(
                name: 'replay',
                modelClients: [$modelClient],
                resultConverters: [$resultConverter],
                modelCatalog: new FallbackModelCatalog(),
                eventDispatcher: $eventDispatcher,
            )],
            eventDispatcher: $eventDispatcher,
        );

        return new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );
    }
}
