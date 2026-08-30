<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\LlmStreamObserverInterface;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAICodex\ResultConverter;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\Delta\DeltaInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter
 */
final class LlmPlatformAdapterTest extends TestCase
{
    public function testSynchronousUnknownExceptionDoesNotBecomeRetryableFromMessageText(): void
    {
        $platform = $this->createStub(SymfonyPlatformInterface::class);
        $platform->method('invoke')->willThrowException(
            new \RuntimeException('Codex WebSocket request frame could not be sent.'),
        );

        $adapter = $this->createAdapter($platform);

        $result = $adapter->invoke(new ModelInvocationRequest(
            model: 'openai-codex/gpt-5.6-sol',
            input: new ModelInvocationInput(
                runId: 'run-sync-ws-send-failure',
                turnNo: 1,
                stepId: 'advance-after-tools-sync',
                messages: [],
            ),
        ));

        $this->assertSame('error', $result->stopReason);
        $this->assertNull($result->assistantMessage);
        $this->assertSame([], $result->deltas);
        $this->assertSame([], $result->usage);
        $this->assertIsArray($result->error);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_PROVIDER, $result->error['error_category'] ?? null);
        $this->assertSame(\RuntimeException::class, $result->error['type'] ?? null);
        $this->assertSame(
            'Codex WebSocket request frame could not be sent.',
            $result->error['message'] ?? null,
        );
        $this->assertSame('openai-codex/gpt-5.6-sol', $result->error['request_model'] ?? null);
    }

    public function testTypedStreamServerFailureUsesBoundedAgentRetry(): void
    {
        $platform = $this->createStub(SymfonyPlatformInterface::class);
        $platform->method('invoke')->willThrowException(new ServerException());

        $result = $this->createAdapter($platform)->invoke(new ModelInvocationRequest(
            model: 'openai-codex/gpt-5.6-sol',
            input: new ModelInvocationInput(runId: 'run-provider-default-retry', turnNo: 1, stepId: 'step-provider-error'),
        ));

        $this->assertSame('error', $result->stopReason);
        $this->assertIsArray($result->error);
        $this->assertTrue($result->error['retryable'] ?? false);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result->error['error_category'] ?? null);
    }

    public function testTypedHttpServerFailureIsTerminalAfterSymfonyRetries(): void
    {
        $platform = $this->createStub(SymfonyPlatformInterface::class);
        $platform->method('invoke')->willThrowException(new ServerException(503));

        $result = $this->createAdapter($platform)->invoke(new ModelInvocationRequest(
            model: 'openai-codex/gpt-5.6-sol',
            input: new ModelInvocationInput(runId: 'run-http-retries-exhausted', turnNo: 1, stepId: 'step-http-error'),
        ));

        $this->assertIsArray($result->error);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame(503, $result->error['http_status_code'] ?? null);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_SERVER, $result->error['error_category'] ?? null);
    }

    public function testLocalProgrammingFailureAtProviderBoundaryRemainsNonRetryable(): void
    {
        $platform = $this->createStub(SymfonyPlatformInterface::class);
        $platform->method('invoke')->willThrowException(new \TypeError('invalid local value'));

        $result = $this->createAdapter($platform)->invoke(new ModelInvocationRequest(
            model: 'openai-codex/gpt-5.6-sol',
            input: new ModelInvocationInput(runId: 'run-local-failure', turnNo: 1, stepId: 'step-local-failure'),
        ));

        $this->assertSame('error', $result->stopReason);
        $this->assertIsArray($result->error);
        $this->assertFalse($result->error['retryable'] ?? true);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_UNKNOWN, $result->error['error_category'] ?? null);
    }

    public function testExtractResponseDiagnosticsOmitsProviderControlledFreeText(): void
    {
        $secret = 'LEAKED_PROVIDER_SECRET_MARKER_adapter_7e4d';
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willReturn(404);
        $httpResponse->method('getContent')->willReturn(json_encode([
            'error' => [
                'code' => 'not_found',
                'type' => 'missing',
                'message' => $secret,
            ],
            'error_description' => $secret,
            'detail' => $secret,
        ]));
        $httpResponse->method('getHeaders')->willReturn(['content-type' => ['application/json']]);

        $raw = new RawHttpResult($httpResponse);
        $deferred = new DeferredResult(
            new ResultConverter(),
            $raw,
            ['stream' => true],
        );

        $reflection = new \ReflectionClass(LlmPlatformAdapter::class);
        $adapter = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('extractResponseDiagnostics');
        $diag = $method->invoke($adapter, $deferred);

        $this->assertSame(404, $diag['http_status_code']);
        $this->assertSame('not_found', $diag['response_error_code']);
        $this->assertSame('missing', $diag['response_error_type']);
        $this->assertArrayNotHasKey('response_error_message', $diag);
        $this->assertTrue($diag['response_body_is_json']);
        $encoded = json_encode($diag);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
    }

    /**
     * Each notify wrapper forwards to the injected observer, and a throwing
     * callback never propagates into model invocation. Invocation order is
     * owned by the streaming consume path — this test exercises the private
     * wrappers in its own order, so it asserts reachability, not order.
     */
    public function testStreamObserverCallbacksAreForwardedAndIsolated(): void
    {
        $observer = new class implements LlmStreamObserverInterface {
            /** @var array<string, true> */
            public array $reached = [];

            public function onStreamStart(string $runId, ?string $stepId): void
            {
                $this->reached['start'] = true;
            }

            public function onDelta(string $runId, ?string $stepId, DeltaInterface $delta): void
            {
                $this->reached['delta'] = true;
                throw new \RuntimeException('observer-delta-failure');
            }

            public function onStreamEnd(string $runId, ?string $stepId): void
            {
                $this->reached['end'] = true;
            }

            public function onStreamError(string $runId, ?string $stepId, \Throwable $error): void
            {
                $this->reached['error'] = true;
                throw new \RuntimeException('observer-error-failure');
            }
        };

        $logger = new TestLogger();
        $adapter = new LlmPlatformAdapter(
            statusReader: new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $this->createStub(SymfonyPlatformInterface::class),
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: $observer,
            costCalculator: null,
            logger: $logger,
            denormalizer: \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $reflection = new \ReflectionClass(LlmPlatformAdapter::class);
        $invoke = static fn (string $method, mixed ...$args): mixed => $reflection->getMethod($method)->invoke($adapter, ...$args);

        // Throwing observers must not propagate out of the wrappers — if they
        // did, the test would error here before reaching the assertions.
        $invoke('notifyStreamStart', 'run-obs', 'step-1');
        $invoke('notifyDelta', 'run-obs', 'step-1', new TextDelta('x'));
        $invoke('notifyStreamEnd', 'run-obs', 'step-1');
        $invoke('notifyStreamError', 'run-obs', 'step-1', new \RuntimeException('original-stream-error'));

        // Order-insensitive reachability: every callback was reached.
        foreach (['start', 'delta', 'end', 'error'] as $callback) {
            $this->assertArrayHasKey($callback, $observer->reached, \sprintf('Observer callback %s must be reached.', $callback));
        }

        $warnings = [];
        foreach ($logger->records as $record) {
            $warnings[$record['message']] = $record['context'];
        }

        $this->assertArrayHasKey('LlmStreamObserver::onDelta threw', $warnings);
        $this->assertSame(TextDelta::class, $warnings['LlmStreamObserver::onDelta threw']['delta_class'] ?? null);
        $deltaException = $warnings['LlmStreamObserver::onDelta threw']['exception'] ?? null;
        $this->assertInstanceOf(\RuntimeException::class, $deltaException);
        $this->assertSame('observer-delta-failure', $deltaException->getMessage());
        $this->assertSame('run-obs', $warnings['LlmStreamObserver::onDelta threw']['run_id'] ?? null);

        $this->assertArrayHasKey('LlmStreamObserver::onStreamError threw', $warnings);
        $observerException = $warnings['LlmStreamObserver::onStreamError threw']['observer_exception'] ?? null;
        $this->assertInstanceOf(\RuntimeException::class, $observerException);
        $this->assertSame('observer-error-failure', $observerException->getMessage());
        $originalError = $warnings['LlmStreamObserver::onStreamError threw']['original_error'] ?? null;
        $this->assertInstanceOf(\RuntimeException::class, $originalError);
        $this->assertSame('original-stream-error', $originalError->getMessage());

        // Empty run ids are suppressed: no callback, no log record.
        $reachedCount = \count($observer->reached);
        $recordCount = \count($logger->records);
        $invoke('notifyStreamStart', '', 'step-1');
        $this->assertCount($reachedCount, $observer->reached, 'Empty run id must not forward to the observer.');
        $this->assertCount($recordCount, $logger->records, 'Empty run id must not produce log records.');
    }

    /**
     * Swallowed diagnostic failures are logged as intentional local
     * degradation with only stable structural fields — exception objects,
     * messages, bodies, and headers never reach the logs.
     */
    public function testDiagnosticFailuresAreLoggedSafelyWithoutSensitiveContent(): void
    {
        $secret = 'DIAGNOSTIC_LEAK_MARKER_adapter_77aa';
        $logger = new TestLogger();

        $reflection = new \ReflectionClass(LlmPlatformAdapter::class);
        $adapter = $reflection->newInstanceWithoutConstructor();
        $reflection->getProperty('logger')->setValue($adapter, $logger);
        $method = $reflection->getMethod('extractResponseDiagnostics');

        // Scenario A: every diagnostic stage fails.
        $httpResponse = $this->createStub(ResponseInterface::class);
        $httpResponse->method('getStatusCode')->willThrowException(new \RuntimeException('STATUS_'.$secret));
        $httpResponse->method('getHeaders')->willThrowException(new \RuntimeException('HEADERS_'.$secret));
        $httpResponse->method('getContent')->willThrowException(new \RuntimeException('BODY_'.$secret));

        $diag = $method->invoke($adapter, new DeferredResult(
            new ResultConverter(),
            new RawHttpResult($httpResponse),
            ['stream' => true],
        ));

        $this->assertSame([
            'http_status_code' => null,
            'response_content_type' => null,
            'response_body_bytes' => null,
            'response_body_is_json' => null,
            'response_error_code' => null,
            'response_error_type' => null,
            'response_error_param' => null,
        ], $diag, 'Failed diagnostic stages must not change the returned shape.');

        $stages = array_map(
            static fn (array $record): mixed => $record['context']['diagnostic_stage'] ?? null,
            array_filter(
                $logger->records,
                static fn (array $record): bool => 'llm.provider.diagnostic_failed' === $record['message'],
            ),
        );
        $this->assertSame(['status_code', 'headers', 'body'], array_values($stages));

        foreach ($logger->records as $record) {
            $this->assertSame('warning', $record['level']);
            $this->assertSame('llm_platform', $record['context']['component'] ?? null);
            $this->assertSame('llm_platform.diagnostic_failed', $record['context']['event_type'] ?? null);
            $this->assertIsString($record['context']['exception_type'] ?? null);
        }

        $encoded = json_encode($logger->records);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded, 'Sensitive exception/header/body text must never reach diagnostic logs.');
    }

    private function createAdapter(SymfonyPlatformInterface $platform): LlmPlatformAdapter
    {
        return new LlmPlatformAdapter(
            statusReader: new \Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            logger: new NullLogger(),
            denormalizer: \Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory::denormalizer(),
        );
    }
}
