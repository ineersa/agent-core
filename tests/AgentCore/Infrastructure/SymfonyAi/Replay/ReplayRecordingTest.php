<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi\Replay;

use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;

#[Group('recording')]
/**
 * Recording tests that capture live LLM deltas into replay fixtures.
 *
 * These tests require a live LLM endpoint (llama.cpp on port 9052).
 * They are NEVER run during normal QA — only invoked via
 * `castor llm:fixtures:record`.
 *
 * The recording path uses {@see StreamRecorderObserver}, which is
 * a test-only {@see LlmStreamObserverInterface} implementation
 * that captures every streaming delta into a JSON fixture array.
 */
final class ReplayRecordingTest extends \PHPUnit\Framework\TestCase
{
    /**
     * Record a simple text-only response from the live test LLM.
     *
     * This is a smoke test for the recording path. It sends a trivial
     * prompt and records whatever deltas the model returns.
     *
     * The fixture is written to a temporary file; it is NOT committed
     * by this test (the Castor command handles that).
     */
    public function testRecordTextOnlyResponseFromLiveLLm(): void
    {
        if (!getenv('LLAMA_CPP_SMOKE_TEST')) {
            $this->markTestSkipped('LLAMA_CPP_SMOKE_TEST not set — recording requires live LLM');
        }

        $recorder = new StreamRecorderObserver();

        $adapter = $this->buildRecordingAdapter($recorder);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'llama_cpp/test',
            input: new ModelInvocationInput(
                runId: 'record-text-run',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'Say "hello world" exactly.']]),
                ],
            ),
        ));

        // The recording captured deltas
        $deltas = $recorder->getDeltas();
        $this->assertNotEmpty($deltas, 'Recording should capture at least one delta');

        // Verify at least one text delta was captured
        $hasText = false;
        foreach ($deltas as $delta) {
            if ('text' === $delta['type'] && '' !== $delta['content']) {
                $hasText = true;
                break;
            }
        }
        $this->assertTrue($hasText, 'Recording should contain at least one text delta');

        // Build the fixture array and verify structure
        $stopReason = StreamRecorderObserver::resolveFixtureStopReason(
            $result->stopReason,
            $recorder->getDeltas(),
        );

        $fixture = $recorder->buildFixture([
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'Say "hello world" exactly.'],
                ],
            ],
            'usage' => $result->usage,
            'stop_reason' => $stopReason,
            'recorded_at' => date('c'),
            'recording_source' => 'llama_cpp_test/test',
        ]);

        $this->assertSame('llama_cpp/test', $fixture['model']);
        $this->assertNotEmpty($fixture['deltas']);

        // Write the fixture (to a temp location, not overwriting committed fixtures)
        $outputPath = sys_get_temp_dir().'/llm-record-fixture-'.uniqid('', true).'.json';
        $stopReason = StreamRecorderObserver::resolveFixtureStopReason(
            $result->stopReason,
            $recorder->getDeltas(),
        );

        $bytes = $recorder->writeFixture($outputPath, [
            'model' => 'llama_cpp/test',
            'provider_id' => 'llama_cpp',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'Say "hello world" exactly.'],
                ],
            ],
            'usage' => $result->usage,
            'stop_reason' => $stopReason,
            'recorded_at' => date('c'),
            'recording_source' => 'llama_cpp_test/test',
        ]);

        $this->assertGreaterThan(0, $bytes, 'Fixture file should be non-empty');
        $this->assertFileExists($outputPath);

        // Optional: print recorded deltas for diagnostic visibility
        echo "\n\nRecorded {$outputPath} (".\count($deltas)." deltas, {$bytes} bytes)\n";
    }

    /**
     * Record the TUI simple-text-response fixture from live LLM.
     *
     * Writes to the path set by HATFIELD_RECORD_TUI_SIMPLE_FIXTURE_PATH
     * when LLAMA_CPP_SMOKE_TEST is also set.  Skips otherwise.
     */
    public function testRecordTuiSimpleTextResponse(): void
    {
        $outputPath = getenv('HATFIELD_RECORD_TUI_SIMPLE_FIXTURE_PATH');
        if (!$outputPath || !getenv('LLAMA_CPP_SMOKE_TEST')) {
            $this->markTestSkipped('HATFIELD_RECORD_TUI_SIMPLE_FIXTURE_PATH / LLAMA_CPP_SMOKE_TEST not set');
        }

        $recorder = new StreamRecorderObserver();
        $adapter = $this->buildRecordingAdapter($recorder);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'llama_cpp/test',
            input: new ModelInvocationInput(
                runId: 'record-tui-simple',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'Respond with exactly one sentence: the sky is blue.']]),
                ],
            ),
        ));

        $deltas = $recorder->getDeltas();
        $this->assertNotEmpty($deltas, 'Recording should capture at least one delta');

        $stopReason = StreamRecorderObserver::resolveFixtureStopReason(
            $result->stopReason,
            $recorder->getDeltas(),
        );

        $bytes = $recorder->writeFixture($outputPath, [
            '$schema' => 'TUI journey reply fixture — simple text response for replay-backed TUI E2E',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => date('c'),
            'recording_source' => 'llama_cpp_test/test',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'Respond with exactly one sentence: the sky is blue.'],
                ],
            ],
            'usage' => $result->usage,
            'stop_reason' => $stopReason,
            'expected_text' => null,
        ]);

        $this->assertGreaterThan(0, $bytes, 'Fixture file should be non-empty');
        echo "\nRecorded TUI simple text fixture → {$outputPath} (".\count($deltas)." deltas, {$bytes} bytes)\n";
    }

    /**
     * Record the TUI startup-prompt-response fixture from live LLM.
     *
     * Writes to the path set by HATFIELD_RECORD_TUI_STARTUP_FIXTURE_PATH
     * when LLAMA_CPP_SMOKE_TEST is also set.  Skips otherwise.
     */
    public function testRecordTuiStartupPromptResponse(): void
    {
        $outputPath = getenv('HATFIELD_RECORD_TUI_STARTUP_FIXTURE_PATH');
        if (!$outputPath || !getenv('LLAMA_CPP_SMOKE_TEST')) {
            $this->markTestSkipped('HATFIELD_RECORD_TUI_STARTUP_FIXTURE_PATH / LLAMA_CPP_SMOKE_TEST not set');
        }

        $recorder = new StreamRecorderObserver();
        $adapter = $this->buildRecordingAdapter($recorder);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'llama_cpp/test',
            input: new ModelInvocationInput(
                runId: 'record-tui-startup',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'hello from tmux e2e']]),
                ],
            ),
        ));

        $deltas = $recorder->getDeltas();
        $this->assertNotEmpty($deltas, 'Recording should capture at least one delta');

        $stopReason = StreamRecorderObserver::resolveFixtureStopReason(
            $result->stopReason,
            $recorder->getDeltas(),
        );

        $bytes = $recorder->writeFixture($outputPath, [
            '$schema' => 'TUI startup snapshot replay fixture — response for auto-submitted --prompt',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => date('c'),
            'recording_source' => 'llama_cpp_test/test',
            'input' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'hello from tmux e2e'],
                ],
            ],
            'usage' => $result->usage,
            'stop_reason' => $stopReason,
            'expected_text' => null,
        ]);

        $this->assertGreaterThan(0, $bytes, 'Fixture file should be non-empty');
        echo "\nRecorded TUI startup fixture → {$outputPath} (".\count($deltas)." deltas, {$bytes} bytes)\n";
    }

    /**
     * Build an adapter wired with a real Symfony AI Platform that talks
     * to llama.cpp, PLUS a StreamRecorderObserver to capture deltas.
     */
    private function buildRecordingAdapter(StreamRecorderObserver $recorder): LlmPlatformAdapter
    {
        // Build a real platform via the factory — this makes actual HTTP calls
        // to the configured LLM endpoint. In test env with LLAMA_CPP_SMOKE_TEST=1,
        // the endpoint is llama_cpp_test/test on port 9052.
        $aiConfig = AiConfig::fromArray([
            'default_model' => 'llama_cpp/test',
            'providers' => [
                'llama_cpp' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => getenv('LLAMA_CPP_BASE_URL') ?: 'http://192.168.2.38:9052/v1',
                    'completions_path' => '/chat/completions',
                    'models' => [
                        'test' => [
                            'id' => 'test',
                            'name' => 'Test',
                            'context_window' => 4096,
                            'max_tokens' => 4096,
                            'input' => ['text'],
                            'reasoning' => false,
                            'tool_calling' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $aiConfig,
            raw: [],
            catalog: new HatfieldModelCatalog($aiConfig),
            cwd: getcwd() ?: '/',
        );

        $eventDispatcher = new EventDispatcher();

        $factory = new SymfonyAiProviderFactory(
            appConfig: $appConfig,
            eventDispatcher: $eventDispatcher,
        );

        $providers = $factory->createProviders();
        $platformProviders = array_values($providers);

        $platform = new \Symfony\AI\Platform\Platform(
            providers: $platformProviders,
            eventDispatcher: $eventDispatcher,
        );

        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'record-run',
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

        return new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: $recorder,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );
    }
}
