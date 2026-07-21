<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Model;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\Model\ExtensionModelCaller;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyPlatformFactoryInterface;
use Ineersa\Hatfield\ExtensionApi\Model\ModelCallException;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Result\DeferredResult;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\ResultConverterInterface;

/**
 * Thesis: callModel validates exact configured models without constructing a
 * provider platform until after validation, uses non-streaming invocation with
 * extension-only tools, maps text/tool/structured results, and sanitizes failures.
 */
final class ExtensionModelCallerTest extends TestCase
{
    public function testRejectsMalformedModelReferenceWithoutCreatingPlatform(): void
    {
        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->never())->method('createPlatform');
        $caller = $this->caller($factory);

        try {
            $caller->call('not-a-model', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_INVALID_MODEL, $e->errorCode);
        }
    }

    public function testRejectsUnknownConfiguredModelWithoutCreatingPlatform(): void
    {
        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->never())->method('createPlatform');
        $caller = $this->caller($factory);

        try {
            $caller->call('llama.cpp/missing', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_UNKNOWN_MODEL, $e->errorCode);
        }
    }

    public function testRejectsAtAliasWithoutImplementingSilentFallback(): void
    {
        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->never())->method('createPlatform');
        $caller = $this->caller($factory);

        try {
            $caller->call('@compaction', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_INVALID_MODEL, $e->errorCode);
        }
    }

    public function testNonStreamingInvokeWithExtensionOnlyToolsMapsTextResult(): void
    {
        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->with(
                'llama.cpp/test',
                $this->anything(),
                $this->callback(static function (array $options): bool {
                    if (false !== ($options['stream'] ?? null)) {
                        return false;
                    }
                    $tools = $options['tools'] ?? null;
                    if (!\is_array($tools) || 1 !== \count($tools)) {
                        return false;
                    }

                    return 'record_observations' === $tools[0]->getName();
                }),
            )
            ->willReturn($this->deferredText('ok'));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $caller = $this->caller($factory);
        $result = $caller->call(
            'llama.cpp/test',
            [['role' => 'user', 'content' => 'observe']],
            [[
                'name' => 'record_observations',
                'description' => 'record',
                'parameters' => ['type' => 'object'],
            ]],
        );

        $this->assertSame('llama.cpp/test', $result->model);
        $this->assertSame('ok', $result->content);
        $this->assertSame([], $result->toolCalls);
    }

    public function testMapsToolCallsWithoutExecutingThem(): void
    {
        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willReturn($this->deferredToolCalls([
                new ToolCall('tc1', 'record_observations', ['items' => [1]]),
            ]));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $caller = $this->caller($factory);
        $result = $caller->call(
            'llama.cpp/test',
            [['role' => 'user', 'content' => 'observe']],
            [['name' => 'record_observations', 'description' => 'record']],
        );

        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('tc1', $result->toolCalls[0]->id);
        $this->assertSame('record_observations', $result->toolCalls[0]->name);
        $this->assertSame(['items' => [1]], $result->toolCalls[0]->arguments);
    }

    public function testMapsObjectResultAsStructuredContent(): void
    {
        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willReturn($this->deferredObject(['ok' => true, 'count' => 2]));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $caller = $this->caller($factory);
        $result = $caller->call(
            'llama.cpp/test',
            [['role' => 'user', 'content' => 'observe']],
            structuredContent: [
                'type' => 'object',
                'properties' => [
                    'ok' => ['type' => 'boolean'],
                    'count' => ['type' => 'integer'],
                ],
            ],
        );

        $this->assertSame(['ok' => true, 'count' => 2], $result->structuredContent);
        $this->assertSame('{"ok":true,"count":2}', $result->content);
    }

    public function testProviderFailureIsSanitized(): void
    {
        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willThrowException(new \RuntimeException('secret api key sk-123 body'));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $logger = new TestLogger();
        $caller = $this->caller($factory, $logger);

        try {
            $caller->call('llama.cpp/test', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_PROVIDER_FAILED, $e->errorCode);
            $this->assertStringNotContainsString('sk-123', $e->getMessage());
            $this->assertStringNotContainsString('secret api key', $e->getMessage());
        }

        $this->assertSame('extension.model_call_failed', $logger->records[0]['message'] ?? null);
        $this->assertSame(ModelCallException::CODE_PROVIDER_FAILED, $logger->records[0]['context']['error_code'] ?? null);
        $this->assertSame('provider_error', $logger->records[0]['context']['error_category'] ?? null);
        $this->assertArrayNotHasKey('exception_message', $logger->records[0]['context'] ?? []);
    }

    public function testUpstreamConnectTimeoutIsProviderTimeoutNotUnsupported(): void
    {
        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willThrowException(new \RuntimeException('upstream connect timeout'));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $logger = new TestLogger();
        $caller = $this->caller($factory, $logger);

        try {
            $caller->call('llama.cpp/test', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_PROVIDER_FAILED, $e->errorCode);
            $this->assertNotSame(ModelCallException::CODE_UNSUPPORTED, $e->errorCode);
            $this->assertStringContainsString('(timeout)', $e->getMessage());
            $this->assertStringNotContainsString('upstream connect timeout', $e->getMessage());
        }

        $this->assertSame(ModelCallException::CODE_PROVIDER_FAILED, $logger->records[0]['context']['error_code'] ?? null);
        $this->assertSame('timeout', $logger->records[0]['context']['error_category'] ?? null);
    }

    public function testExplicitStreamingOnlyPhraseIsUnsupportedAndLoggedConsistently(): void
    {
        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willThrowException(new \RuntimeException('provider is stream only'));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $logger = new TestLogger();
        $caller = $this->caller($factory, $logger);

        try {
            $caller->call('llama.cpp/test', [['role' => 'user', 'content' => 'hi']]);
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_UNSUPPORTED, $e->errorCode);
        }

        $this->assertSame(ModelCallException::CODE_UNSUPPORTED, $logger->records[0]['context']['error_code'] ?? null);
        $this->assertSame('unsupported', $logger->records[0]['context']['error_category'] ?? null);
    }

    public function testResultMappingJsonFailureBecomesSanitizedProviderFailed(): void
    {
        $cyclic = new \stdClass();
        $cyclic->self = $cyclic;

        $platform = $this->createMock(SymfonyPlatformInterface::class);
        $platform->expects($this->once())
            ->method('invoke')
            ->willReturn($this->deferredObject($cyclic));

        $factory = $this->createMock(SymfonyPlatformFactoryInterface::class);
        $factory->expects($this->once())->method('createPlatform')->willReturn($platform);

        $logger = new TestLogger();
        $caller = $this->caller($factory, $logger);

        try {
            $caller->call(
                'llama.cpp/test',
                [['role' => 'user', 'content' => 'observe']],
                structuredContent: [
                    'type' => 'object',
                    'properties' => ['ok' => ['type' => 'boolean']],
                ],
            );
            $this->fail('Expected ModelCallException');
        } catch (ModelCallException $e) {
            $this->assertSame(ModelCallException::CODE_PROVIDER_FAILED, $e->errorCode);
            $this->assertStringContainsString('(result_mapping_failed)', $e->getMessage());
            $this->assertStringNotContainsString('Recursion', $e->getMessage());
        }

        $this->assertSame('extension.model_call_failed', $logger->records[0]['message'] ?? null);
        $this->assertSame('model_call_result_mapping_failed', $logger->records[0]['context']['event_type'] ?? null);
        $this->assertSame(ModelCallException::CODE_PROVIDER_FAILED, $logger->records[0]['context']['error_code'] ?? null);
        $this->assertSame('result_mapping_failed', $logger->records[0]['context']['error_category'] ?? null);
        $this->assertArrayNotHasKey('exception_message', $logger->records[0]['context'] ?? []);
    }

    private function caller(
        SymfonyPlatformFactoryInterface $factory,
        ?TestLogger $logger = null,
    ): ExtensionModelCaller {
        $catalog = new HatfieldModelCatalog(new AiConfig(
            defaultModel: 'llama.cpp/test',
            providers: [
                'llama.cpp' => new AiProviderConfig(
                    id: 'llama.cpp',
                    enabled: true,
                    models: [
                        'test' => new AiModelDefinition(
                            id: 'test',
                            name: 'test',
                            contextWindow: 8192,
                        ),
                    ],
                ),
            ],
        ));

        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            catalog: $catalog,
            cwd: '/',
        );

        return new ExtensionModelCaller($appConfig, $factory, $logger ?? new TestLogger());
    }

    private function deferredText(string $text): DeferredResult
    {
        $raw = $this->createStub(RawResultInterface::class);
        $converter = new class($text) implements ResultConverterInterface {
            public function __construct(private string $text)
            {
            }

            public function supports(\Symfony\AI\Platform\Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): TextResult
            {
                return new TextResult($this->text);
            }

            public function getTokenUsageExtractor(): ?\Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface
            {
                return null;
            }
        };

        return new DeferredResult($converter, $raw);
    }

    /**
     * @param list<ToolCall> $toolCalls
     */
    private function deferredToolCalls(array $toolCalls): DeferredResult
    {
        $raw = $this->createStub(RawResultInterface::class);
        $converter = new class($toolCalls) implements ResultConverterInterface {
            /** @param list<ToolCall> $toolCalls */
            public function __construct(private array $toolCalls)
            {
            }

            public function supports(\Symfony\AI\Platform\Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ToolCallResult
            {
                return new ToolCallResult($this->toolCalls);
            }

            public function getTokenUsageExtractor(): ?\Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface
            {
                return null;
            }
        };

        return new DeferredResult($converter, $raw);
    }

    /**
     * @param object|array<string, mixed> $object
     */
    private function deferredObject(object|array $object): DeferredResult
    {
        $raw = $this->createStub(RawResultInterface::class);
        $converter = new class($object) implements ResultConverterInterface {
            /** @param object|array<string, mixed> $object */
            public function __construct(private object|array $object)
            {
            }

            public function supports(\Symfony\AI\Platform\Model $model): bool
            {
                return true;
            }

            public function convert(RawResultInterface $result, array $options = []): ObjectResult
            {
                return new ObjectResult($this->object);
            }

            public function getTokenUsageExtractor(): ?\Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface
            {
                return null;
            }
        };

        return new DeferredResult($converter, $raw);
    }
}
