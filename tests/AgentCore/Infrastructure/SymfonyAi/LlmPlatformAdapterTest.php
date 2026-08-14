<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmProviderErrorClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;

/**
 * @covers \Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter
 */
final class LlmPlatformAdapterTest extends TestCase
{
    public function testExtraOptionsAreForwardedAndNoToolsFlagWins(): void
    {
        $options = $this->buildInputOptions(new ModelInvocationRequest(
            model: 'test/provider',
            input: new ModelInvocationInput(
                runId: 'run-1',
                turnNo: 7,
                toolsRef: 'toolset:run-1:turn-7',
            ),
            options: new ModelInvocationOptions(
                extraOptions: [
                    'thinking_level' => 'low',
                    'tools' => ['should-not-survive'],
                    'temperature' => 0.2,
                ],
                toolsEnabled: false,
            ),
        ));

        $this->assertSame('toolset:run-1:turn-7', $options['tools_ref']);
        $this->assertSame(7, $options['turn_no']);
        $this->assertSame('run-1', $options['run_id']);
        $this->assertSame('low', $options['thinking_level']);
        $this->assertSame(0.2, $options['temperature']);
        $this->assertSame([], $options['tools'], 'toolsEnabled:false must override any tools key from generic extra options.');
    }

    public function testSynchronousPlatformInvokeExceptionReturnsRetryableNetworkErrorResult(): void
    {
        $platform = $this->createStub(SymfonyPlatformInterface::class);
        $platform->method('invoke')->willThrowException(
            new \RuntimeException('Codex WebSocket request frame could not be sent.'),
        );

        $adapter = new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
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
        $this->assertTrue($result->error['retryable'] ?? false);
        $this->assertSame(LlmProviderErrorClassifier::CATEGORY_NETWORK, $result->error['error_category'] ?? null);
        $this->assertSame(\RuntimeException::class, $result->error['type'] ?? null);
        $this->assertSame(
            'Codex WebSocket request frame could not be sent.',
            $result->error['message'] ?? null,
        );
        $this->assertSame('openai-codex/gpt-5.6-sol', $result->error['request_model'] ?? null);
    }

    public function testExtractResponseDiagnosticsOmitsProviderControlledFreeText(): void
    {
        $secret = 'LEAKED_PROVIDER_SECRET_MARKER_adapter_7e4d';
        $httpResponse = $this->createStub(\Symfony\Contracts\HttpClient\ResponseInterface::class);
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

        $raw = new \Symfony\AI\Platform\Result\RawHttpResult($httpResponse);
        $deferred = new \Symfony\AI\Platform\Result\DeferredResult(
            new \Symfony\AI\Platform\Bridge\OpenAICodex\ResultConverter(),
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
        $this->assertNull($diag['response_error_message']);
        $this->assertTrue($diag['response_body_is_json']);
        $encoded = json_encode($diag);
        $this->assertIsString($encoded);
        $this->assertStringNotContainsString($secret, $encoded);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildInputOptions(ModelInvocationRequest $request): array
    {
        $reflection = new \ReflectionClass(LlmPlatformAdapter::class);
        $adapter = $reflection->newInstanceWithoutConstructor();
        $method = $reflection->getMethod('buildInputOptions');

        /** @var array<string, mixed> $options */
        $options = $method->invoke($adapter, $request);

        return $options;
    }
}
