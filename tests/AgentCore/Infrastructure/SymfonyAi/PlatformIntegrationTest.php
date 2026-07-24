<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Infrastructure\SymfonyAi;

use Ineersa\AgentCore\Contract\Hook\BeforeProviderRequestHookInterface;
use Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface;
use Ineersa\AgentCore\Contract\Hook\ConvertToLlmHookInterface;
use Ineersa\AgentCore\Contract\Hook\TransformContextHookInterface;
use Ineersa\AgentCore\Contract\Model\ModelResolverInterface;
use Ineersa\AgentCore\Contract\Model\ProviderRegistryInterface;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Model\ProviderRequest;
use Ineersa\AgentCore\Domain\Model\ResolvedModel;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Domain\Run\RunStatus;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\BeforeProviderRequestSubscriber;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\PlatformInvocationMetadata;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface as SymfonyPlatformInterface;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\InMemoryRawResult;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\AI\Platform\Tool\ExecutionReference;
use Symfony\AI\Platform\Tool\Tool;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class PlatformIntegrationTest extends TestCase
{
    public function testPlatformInvokerAppliesHookChainAndInjectsDynamicToolDescriptions(): void
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'run-stage-05',
            status: RunStatus::Running,
            version: 1,
            turnNo: 2,
            lastSeq: 5,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'ping']])],
            activeStepId: 'turn-2-llm-1',
            model: 'test-model'), 0);

        $calls = [];

        $transformHook = new class($calls) implements TransformContextHookInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
            {
                $this->calls[] = 'transform_context';

                return $messages;
            }
        };

        $convertHook = new class($calls) implements ConvertToLlmHookInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function convertToLlm(array $messages, ?CancellationTokenInterface $cancelToken = null, string $modelName = ''): \Symfony\AI\Platform\Message\MessageBag
            {
                $this->calls[] = 'convert_to_llm';

                return new \Symfony\AI\Platform\Message\MessageBag(\Symfony\AI\Platform\Message\Message::ofUser('converted'));
            }
        };

        $beforeProviderHook = new class($calls) implements BeforeProviderRequestHookInterface {
            /** @param list<string> $calls */
            public function __construct(private array &$calls)
            {
            }

            public function beforeProviderRequest(string $model, array $input, array $options, ?CancellationTokenInterface $cancelToken = null): ?ProviderRequest
            {
                $this->calls[] = 'before_provider_request';

                return new ProviderRequest(
                    model: $model.'-patched',
                    input: $input,
                    options: array_replace($options, ['temperature' => 0.2]),
                );
            }
        };

        $modelResolver = new class implements ModelResolverInterface {
            public function resolve(
                string $defaultModel,
                \Symfony\AI\Platform\Message\MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                unset($messages, $input, $options);

                return new ResolvedModel(model: $defaultModel.'-resolved', options: ['max_tokens' => 64]);
            }
        };

        $toolbox = new class implements ToolboxInterface {
            public function getTools(): array
            {
                return [new Tool(
                    reference: new ExecutionReference(self::class),
                    name: 'web_search',
                    description: 'Search docs for turn 2',
                    parameters: [
                        'type' => 'object',
                        'properties' => [
                            'query' => ['type' => 'string'],
                        ],
                        'required' => ['query'],
                    ],
                )];
            }

            public function execute(ToolCall $toolCall): ToolResult
            {
                throw new \LogicException('Not used in this test.');
            }
        };

        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage(promptTokens: 7, completionTokens: 3, totalTokens: 10));
        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            streamFactory: static fn (): iterable => [
                new TextDelta('Hello'),
                new TextDelta(' world'),
            ],
            beforeProviderRequestHooks: [$beforeProviderHook],
            modelResolver: $modelResolver,
        );

        $adapter = new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor($toolbox),
            platform: $platform,
            transformContextHooks: [$transformHook],
            convertToLlmHooks: [$convertHook],
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'gpt-test',
            input: new ModelInvocationInput(
                runId: 'run-stage-05',
                turnNo: 2,
                stepId: 'turn-2-llm-1',
            ),
        ));

        $this->assertSame(['transform_context', 'convert_to_llm', 'before_provider_request'], $calls);
        $this->assertSame('gpt-test-resolved-patched', $modelClient->capturedModel);
        $this->assertTrue($modelClient->capturedOptions['stream']);
        $this->assertSame(64, $modelClient->capturedOptions['max_tokens']);
        $this->assertSame(0.2, $modelClient->capturedOptions['temperature']);
        $this->assertSame('Search docs for turn 2', $modelClient->capturedOptions['tools'][0]['function']['description']);
        $this->assertArrayNotHasKey(PlatformInvocationMetadata::OPTION_KEY, $modelClient->capturedOptions);

        $this->assertSame('Hello world', $response->assistantMessage?->asText());
        $this->assertNull($response->stopReason);
        $this->assertSame(7, $response->usage['input_tokens']);
        $this->assertSame(3, $response->usage['output_tokens']);
        $this->assertSame(10, $response->usage['total_tokens']);
    }

    /**
     * Proves the model catalog handles provider-qualified model names.
     *
     * When the model resolver returns "llama_cpp/flash" and the subscriber
     * sets an explicit provider, the full qualified name reaches the
     * provider's own ProjectedSymfonyModelCatalog.  The catalog must
     * accept "llama_cpp/flash" by looking up the bare name "flash".
     */
    public function testProviderQualifiedModelNameIsResolvedByCatalog(): void
    {
        $eventDispatcher = new EventDispatcher();

        $modelResolver = new class implements ModelResolverInterface {
            public function resolve(
                string $defaultModel,
                \Symfony\AI\Platform\Message\MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                unset($messages, $input, $options);

                return new ResolvedModel(
                    model: 'llama_cpp/flash',
                    providerId: 'llama_cpp',
                );
            }
        };

        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage());

        // Use a real ProjectedSymfonyModelCatalog (with the new
        // provider-prefix-aware parseModelName) instead of FallbackModelCatalog.
        // The catalog is seeded with the bare model name "flash" —
        // as production's SymfonyAiProviderFactory does.
        $provider = new Provider(
            name: 'llama_cpp',
            modelClients: [$modelClient],
            resultConverters: [new FakeStreamResultConverter(
                static fn (): iterable => [new TextDelta('response')],
            )],
            modelCatalog: new ProjectedSymfonyModelCatalog([
                'flash' => new AiModelDefinition(
                    id: 'flash',
                    name: 'flash',
                    contextWindow: 8000,
                    maxTokens: 4096,
                    input: ['text'],
                    toolCalling: false,
                    reasoning: false,
                ),
            ]),
            eventDispatcher: $eventDispatcher,
        );

        $providerRegistry = new class($provider) implements ProviderRegistryInterface {
            public function __construct(private Provider $provider)
            {
            }

            public function get(string $id): ?\Symfony\AI\Platform\ProviderInterface
            {
                return 'llama_cpp' === $id ? $this->provider : null;
            }

            public function all(): array
            {
                return ['llama_cpp' => $this->provider];
            }
        };

        $eventDispatcher->addSubscriber(
            new ModelResolverRoutingSubscriber($modelResolver, $providerRegistry),
        );

        $platform = new Platform(
            providers: [$provider],
            eventDispatcher: $eventDispatcher,
        );

        $messageBag = new \Symfony\AI\Platform\Message\MessageBag(\Symfony\AI\Platform\Message\Message::ofUser('Hello'));

        $result = $platform->invoke(
            model: 'llama_cpp/flash',
            input: [
                'message_bag' => $messageBag,
            ],
            options: PlatformInvocationMetadata::inject([], new PlatformInvocationMetadata(
                input: new ModelInvocationInput(),
                cancelToken: new ToggleCancellationToken(),
            )),
        );

        // The key regression assertion: invoke() succeeded — no
        // ModelNotFoundException from the catalog.  The
        // ProjectedSymfonyModelCatalog's parseModelName override
        // accepted "llama_cpp/flash" and resolved it to "flash".
        $this->assertNotNull($result);
    }

    /**
     * Regression test for GitHub issue #122 cost path.
     *
     * The bug: when ModelInvocationRequest->model is empty string (the
     * legacy app.default_model container parameter) and the real model
     * is resolved later via ModelResolverInterface, cost calculation
     * was skipped because extractUsage received the empty model name.
     *
     * After the fix (b86e4aba), LlmPlatformAdapter resolves the model
     * via ModelResolverInterface BEFORE consuming the stream, so
     * extractUsage receives the real model ref and computes cost.
     */
    public function testCostIsCalculatedWhenRequestModelIsEmptyButResolverReturnsPricedModel(): void
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'run-cost-01',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'ping']])],
            activeStepId: 'turn-1-llm-1',
            model: 'test-model'), 0);

        $costCalculator = new class implements \Ineersa\AgentCore\Domain\Model\CostCalculatorInterface {
            public ?string $capturedModel = null;

            /** @var array<string, mixed> */
            public array $capturedUsage = [];

            public function calculateCost(string $modelRef, array $usage): float
            {
                $this->capturedModel = $modelRef;
                $this->capturedUsage = $usage;

                return 42.0;
            }
        };

        $modelResolver = new class implements ModelResolverInterface {
            public function resolve(
                string $defaultModel,
                \Symfony\AI\Platform\Message\MessageBag $messages,
                ModelInvocationInput $input,
                ModelResolutionOptions $options,
            ): ResolvedModel {
                unset($defaultModel, $messages, $input, $options);

                return new ResolvedModel(model: 'test/priced-model');
            }
        };

        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage(
            promptTokens: 1000,
            completionTokens: 500,
            totalTokens: 1500,
        ));

        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            streamFactory: static fn (): iterable => [new TextDelta('response')],
            modelResolver: $modelResolver,
        );

        $adapter = new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: $costCalculator,
            modelResolver: $modelResolver,
            logger: new NullLogger(),
        );

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: '', // empty — the legacy app.default_model
            input: new ModelInvocationInput(
                runId: 'run-cost-01',
                turnNo: 1,
                stepId: 'turn-1-llm-1',
            ),
        ));

        // The cost calculator must have been called with the resolved model ref,
        // NOT the empty request model.  This was the root cause: empty model
        // skipped cost calculation at the '' !== $modelName guard.
        $this->assertSame('test/priced-model', $costCalculator->capturedModel, 'Cost calculator should receive the resolved model, not the empty request model.');
        $this->assertSame(1000, $costCalculator->capturedUsage['input_tokens'] ?? null);
        $this->assertSame(500, $costCalculator->capturedUsage['output_tokens'] ?? null);

        // The usage array must contain the computed cost.
        $this->assertArrayHasKey('cost', $response->usage, 'Usage should contain a cost key.');
        $this->assertSame(42.0, $response->usage['cost']);

        // Token counts still flow through independently.
        $this->assertSame(1000, $response->usage['input_tokens']);
        $this->assertSame(500, $response->usage['output_tokens']);
        $this->assertSame(1500, $response->usage['total_tokens']);
    }

    public function testImageRefSurvivesAdapterConvertWhenRequestCarriesActualVisionModel(): void
    {
        $tmpDir = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createOsTempDir('platform-image-gate');
        try {
            $imagePath = $tmpDir.'/vision-gate.png';
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
            file_put_contents($imagePath, $png);

            $runStore = new InMemoryRunStore();
            $runStore->compareAndSwap(new RunState(
                runId: 'run-image-gate-01',
                status: RunStatus::Running,
                version: 1,
                turnNo: 1,
                lastSeq: 0,
                isStreaming: false,
                streamingMessage: null,
                pendingToolCalls: [],
                errorMessage: null,
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'describe image']]),
                    new AgentMessage(
                        role: 'assistant',
                        content: [['type' => 'text', 'text' => 'Calling view_image']],
                        metadata: [
                            'tool_calls' => [[
                                'id' => 'call_view',
                                'name' => 'view_image',
                                'args' => ['path' => $imagePath],
                                'order_index' => 0,
                            ]],
                        ],
                    ),
                    new AgentMessage(
                        role: 'tool',
                        content: [
                            ['type' => 'text', 'text' => '{"type":"view_image"}'],
                            [
                                'type' => 'image_ref',
                                'path' => $imagePath,
                                'media_type' => 'image/png',
                                'bytes' => \strlen($png),
                                'width' => 1,
                                'height' => 1,
                            ],
                        ],
                        toolCallId: 'call_view',
                        toolName: 'view_image',
                    ),
                ],
                activeStepId: 'turn-1-llm-1',
                model: 'test-model'), 0);

            $checker = $this->createStub(\Ineersa\AgentCore\Contract\Model\ImageCapabilityCheckerInterface::class);
            $checker->method('supportsImages')->willReturn(true);

            $imageGatingHook = new \Ineersa\CodingAgent\Tool\ImageProcessing\ImageGatingConvertHook(
                $checker,
                new AgentMessageConverter(),
            );

            $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage(
                promptTokens: 10,
                completionTokens: 5,
                totalTokens: 15,
            ));

            $platform = $this->createSymfonyPlatform(
                modelClient: $modelClient,
                streamFactory: static fn (): iterable => [new TextDelta('ok')],
            );

            $adapter = new LlmPlatformAdapter(
                runStore: $runStore,
                messageConverter: new AgentMessageConverter(),
                toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
                platform: $platform,
                transformContextHooks: [],
                convertToLlmHooks: [$imageGatingHook],
                streamObserver: null,
                costCalculator: null,
                logger: new NullLogger(),
            );

            $adapter->invoke(new ModelInvocationRequest(
                model: 'llama_cpp/flash',
                input: new ModelInvocationInput(
                    runId: 'run-image-gate-01',
                    turnNo: 1,
                    stepId: 'turn-1-llm-1',
                ),
            ));

            $payload = $modelClient->capturedPayload;
            $this->assertIsArray($payload);
            $serialized = json_encode($payload);
            $this->assertIsString($serialized);
            $this->assertStringNotContainsString('does not support images', $serialized, 'Convert hooks must receive the actual request model, not an empty sentinel.');
        } finally {
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    public function testStreamingCancellationReturnsAbortedWithPartialOutput(): void
    {
        $platform = $this->createSymfonyPlatform(
            modelClient: new FakeSymfonyModelClient(new FakeTokenUsage(promptTokens: 11, completionTokens: 4, totalTokens: 15)),
            streamFactory: static fn (): iterable => [
                new TextDelta('A'),
                new TextDelta('B'),
                new TextDelta('C'),
            ],
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
            modelResolver: null,
            logger: new NullLogger(),
        );

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'gpt-test',
            input: new ModelInvocationInput(
                messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'cancel me']])],
            ),
            options: new ModelInvocationOptions(
                cancelToken: new ToggleCancellationToken(),
            ),
        ));

        $this->assertSame('aborted', $response->stopReason);
        $this->assertSame('A', $response->assistantMessage?->asText());
        $this->assertSame(15, $response->usage['total_tokens']);
    }

    public function testTransformHookNotificationsFlowToPlatformInvocationResult(): void
    {
        $runStore = new InMemoryRunStore();
        $streamFactory = static function (): \Generator {
            yield new TextDelta('response');
        };

        $transformHook = new class implements TransformContextHookInterface {
            public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
            {
                // Attach a model_notification to the last AgentMessage to
                // simulate a defense-in-depth output cap firing.
                $lastIndex = \count($messages) - 1;
                if (isset($messages[$lastIndex])) {
                    $msg = $messages[$lastIndex];
                    $notification = [
                        'id' => hash('sha256', 'defense-cap-'.$msg->toolCallId),
                        'source' => 'output_cap',
                        'kind' => 'output_capped',
                        'severity' => 'warning',
                        'delivery' => 'tool_result_replace',
                        'text' => '[Output capped by defense-in-depth]',
                        'tool_call_id' => $msg->toolCallId,
                        'tool_name' => $msg->toolName,
                    ];

                    $details = \is_array($msg->details) ? $msg->details : [];
                    $existing = \is_array($details['model_notifications'] ?? null)
                        ? $details['model_notifications']
                        : [];
                    $existing[] = $notification;
                    $details['model_notifications'] = $existing;

                    $messages[$lastIndex] = new AgentMessage(
                        role: $msg->role,
                        content: $msg->content,
                        toolCallId: $msg->toolCallId,
                        toolName: $msg->toolName,
                        details: $details,
                        isError: $msg->isError,
                    );
                }

                return $messages;
            }
        };

        $adapter = $this->createAdapter($runStore, streamFactory: $streamFactory, transformHooks: [$transformHook]);

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'fake',
            input: new ModelInvocationInput(
                runId: 'run-hook-notif',
                turnNo: 1,
                stepId: 'step-1',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'hello']]),
                    new AgentMessage(
                        role: 'assistant',
                        content: [['type' => 'text', 'text' => 'Calling read tool']],
                        metadata: [
                            'tool_calls' => [[
                                'id' => 'call-def-1',
                                'name' => 'read',
                                'args' => ['path' => './file.txt'],
                                'order_index' => 0,
                            ]],
                        ],
                    ),
                    new AgentMessage('tool', [['type' => 'text', 'text' => 'some output']],
                        toolCallId: 'call-def-1',
                        toolName: 'read',
                    ),
                ],
            ),
            options: new ModelInvocationOptions(),
        ));

        // The transform hook's notification must appear in the
        // PlatformInvocationResult.
        $this->assertCount(1, $response->modelNotifications);
        $this->assertSame('output_cap', $response->modelNotifications[0]['source']);
        $this->assertSame('output_capped', $response->modelNotifications[0]['kind']);
        $this->assertSame('tool_result_replace', $response->modelNotifications[0]['delivery']);
        $this->assertSame('call-def-1', $response->modelNotifications[0]['tool_call_id']);

        // Normal (success) response.
        $this->assertNull($response->error);
        $this->assertNotNull($response->assistantMessage);
    }

    public function testTransformHookNotificationsFlowOnProviderError(): void
    {
        $runStore = new InMemoryRunStore();

        // Stream factory that throws after yielding one delta,
        // simulating a provider stream error.
        $streamFactory = static function (): \Generator {
            yield new TextDelta('partial');
            throw new \RuntimeException('Provider stream error');
        };

        $transformHook = new class implements TransformContextHookInterface {
            public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
            {
                $notification = [
                    'id' => hash('sha256', 'err-cap'),
                    'source' => 'output_cap',
                    'kind' => 'output_capped',
                    'severity' => 'warning',
                    'delivery' => 'tool_result_replace',
                    'text' => '[Output capped]',
                    'tool_call_id' => 'call-err',
                ];

                if (isset($messages[0])) {
                    $msg = $messages[0];
                    $details = \is_array($msg->details) ? $msg->details : [];
                    $details['model_notifications'] = [$notification];
                    $messages[0] = new AgentMessage(
                        role: $msg->role,
                        content: $msg->content,
                        details: $details,
                    );
                }

                return $messages;
            }
        };

        $adapter = $this->createAdapter($runStore, streamFactory: $streamFactory, transformHooks: [$transformHook]);

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'fake',
            input: new ModelInvocationInput(
                runId: 'run-err-notif',
                turnNo: 1,
                stepId: 'step-err',
                messages: [
                    new AgentMessage('user', [['type' => 'text', 'text' => 'hello']]),
                ],
            ),
            options: new ModelInvocationOptions(),
        ));

        // Notifications must still be present even on error.
        $this->assertNotNull($response->error);
        $this->assertCount(1, $response->modelNotifications);
        $this->assertSame('call-err', $response->modelNotifications[0]['tool_call_id']);
    }

    public function testNotificationsPresentPreTransformAreNotReEmitted(): void
    {
        $runStore = new InMemoryRunStore();
        $streamFactory = static function (): \Generator {
            yield new TextDelta('response');
        };

        $existingNid = hash('sha256', 'existing-notif');

        // Pre-transform: an AgentMessage already has a model_notification.
        $preMsg = new AgentMessage(
            role: 'tool',
            content: [['type' => 'text', 'text' => 'capped output']],
            toolCallId: 'call-exists',
            toolName: 'read',
            details: [
                'model_notifications' => [[
                    'id' => $existingNid,
                    'source' => 'output_cap',
                    'kind' => 'output_capped',
                    'severity' => 'warning',
                    'delivery' => 'tool_result_replace',
                    'text' => '[Output capped to 100 chars]',
                    'tool_call_id' => 'call-exists',
                ]],
            ],
        );

        // Transform hook that adds a NEW notification to the
        // last message (simulating defense-in-depth on a different tool).
        $transformHook = new class implements TransformContextHookInterface {
            public function transformContext(array $messages, ?CancellationTokenInterface $cancelToken = null): array
            {
                $lastIndex = \count($messages) - 1;
                if (isset($messages[$lastIndex])) {
                    $msg = $messages[$lastIndex];
                    $newNid = hash('sha256', 'defense-new-notif');
                    $notification = [
                        'id' => $newNid,
                        'source' => 'output_cap',
                        'kind' => 'output_capped',
                        'severity' => 'warning',
                        'delivery' => 'tool_result_replace',
                        'text' => '[Output capped by defense]',
                        'tool_call_id' => $msg->toolCallId,
                    ];

                    $details = \is_array($msg->details) ? $msg->details : [];
                    $existing = \is_array($details['model_notifications'] ?? null)
                        ? $details['model_notifications']
                        : [];
                    $existing[] = $notification;
                    $details['model_notifications'] = $existing;

                    $messages[$lastIndex] = new AgentMessage(
                        role: $msg->role,
                        content: $msg->content,
                        toolCallId: $msg->toolCallId,
                        details: $details,
                    );
                }

                return $messages;
            }
        };

        $adapter = $this->createAdapter($runStore, streamFactory: $streamFactory, transformHooks: [$transformHook]);

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'fake',
            input: new ModelInvocationInput(
                runId: 'run-dedup',
                turnNo: 1,
                stepId: 'step-dedup',
                messages: [
                    new AgentMessage(
                        role: 'assistant',
                        content: [['type' => 'text', 'text' => 'Calling read tool']],
                        metadata: [
                            'tool_calls' => [[
                                'id' => 'call-exists',
                                'name' => 'read',
                                'args' => [],
                                'order_index' => 0,
                            ]],
                        ],
                    ),
                    $preMsg,
                ],
            ),
            options: new ModelInvocationOptions(),
        ));

        // Only the NEW notification must be in modelNotifications.
        $this->assertCount(1, $response->modelNotifications);
        $this->assertNotSame($existingNid, $response->modelNotifications[0]['id']);
        $this->assertStringContainsString('defense', $response->modelNotifications[0]['text']);
    }

    /**
     * Verify that cache-read and cache-creation tokens flow from
     * TokenUsageInterface through extractUsage into the response usage
     * payload.  This is the path from LlmPlatformAdapter → events →
     * UsageProjection → TUI footer.
     */
    public function testCacheReadTokensFlowToUsagePayload(): void
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'run-cache-01',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'ping']])],
            activeStepId: 'turn-1-llm-1',
            model: 'test-model'), 0);

        // Fake token usage with cache-read tokens but no explicit cache-creation.
        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage(
            promptTokens: 100,
            completionTokens: 50,
            cachedTokens: 78,
            cacheReadTokens: 78,
            totalTokens: 150,
        ));

        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            streamFactory: static fn (): iterable => [new TextDelta('response')],
        );

        $adapter = new LlmPlatformAdapter(
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

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'fake',
            input: new ModelInvocationInput(
                runId: 'run-cache-01',
                turnNo: 1,
                stepId: 'step-cache-01',
            ),
        ));

        // The usage payload must contain the cache fields.
        $this->assertArrayHasKey('cached_tokens', $response->usage, 'Usage must contain cached_tokens');
        $this->assertSame(78, $response->usage['cached_tokens']);

        $this->assertArrayHasKey('cache_read_tokens', $response->usage, 'Usage must contain cache_read_tokens');
        $this->assertSame(78, $response->usage['cache_read_tokens']);

        $this->assertArrayNotHasKey('cache_creation_tokens', $response->usage, 'cache_creation_tokens must be absent when not reported');

        // Standard fields still present.
        $this->assertSame(100, $response->usage['input_tokens']);
        $this->assertSame(50, $response->usage['output_tokens']);
        $this->assertSame(150, $response->usage['total_tokens']);
    }

    /**
     * Verify that cache-read falls back to getCachedTokens() when
     * getCacheReadTokens() returns null — the incremental provider
     * compatibility fallback.
     */
    public function testCacheReadTokensFallbackToCachedTokensInUsagePayload(): void
    {
        $runStore = new InMemoryRunStore();
        $runStore->compareAndSwap(new RunState(
            runId: 'run-cache-02',
            status: RunStatus::Running,
            version: 1,
            turnNo: 1,
            lastSeq: 0,
            isStreaming: false,
            streamingMessage: null,
            pendingToolCalls: [],
            errorMessage: null,
            messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'ping']])],
            activeStepId: 'turn-1-llm-1',
            model: 'test-model'), 0);

        // Only aggregate cached_tokens; no explicit cache_read_tokens.
        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage(
            promptTokens: 200,
            completionTokens: 80,
            cachedTokens: 120,
            cacheReadTokens: null,
            totalTokens: 280,
        ));

        $platform = $this->createSymfonyPlatform(
            modelClient: $modelClient,
            streamFactory: static fn (): iterable => [new TextDelta('response')],
        );

        $adapter = new LlmPlatformAdapter(
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

        $response = $adapter->invoke(new ModelInvocationRequest(
            model: 'fake',
            input: new ModelInvocationInput(
                runId: 'run-cache-02',
                turnNo: 1,
                stepId: 'step-cache-02',
            ),
        ));

        // cache_read_tokens must fall back to cached_tokens when explicit
        // cache-read is absent.
        $this->assertArrayHasKey('cache_read_tokens', $response->usage);
        $this->assertSame(120, $response->usage['cache_read_tokens'], 'cache_read_tokens must fall back to cached_tokens');
        $this->assertSame(120, $response->usage['cached_tokens']);
    }

    /**
     * Create an LlmPlatformAdapter with a fake Symfony AI backend,
     * a simple text-delta stream, and the given transform hooks.
     *
     * @param \Closure(): iterable<mixed>             $streamFactory
     * @param iterable<TransformContextHookInterface> $transformHooks
     * @param iterable<ConvertToLlmHookInterface>     $convertHooks
     */
    private function createAdapter(
        InMemoryRunStore $runStore,
        ?\Closure $streamFactory = null,
        iterable $transformHooks = [],
        iterable $convertHooks = [],
    ): LlmPlatformAdapter {
        $modelClient = new FakeSymfonyModelClient(new FakeTokenUsage());
        $platform = $this->createSymfonyPlatform($modelClient, $streamFactory ?? static function (): \Generator {
            yield new TextDelta('response');
        });

        return new LlmPlatformAdapter(
            runStore: $runStore,
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(
                new class implements ToolboxInterface {
                    public function execute(ToolCall $toolCall): ToolResult
                    {
                        return new ToolResult(new Text(''));
                    }

                    public function getToolIterator(): \Traversable
                    {
                        return new \ArrayIterator([]);
                    }

                    public function getTools(): array
                    {
                        return [];
                    }
                },
            ),
            platform: $platform,
            transformContextHooks: $transformHooks,
            convertToLlmHooks: $convertHooks,
            streamObserver: null,
            costCalculator: null,
            modelResolver: null,
            logger: new NullLogger(),
        );
    }

    /**
     * @param iterable<BeforeProviderRequestHookInterface> $beforeProviderRequestHooks
     * @param \Closure(): iterable<mixed>                  $streamFactory
     */
    private function createSymfonyPlatform(
        FakeSymfonyModelClient $modelClient,
        \Closure $streamFactory,
        iterable $beforeProviderRequestHooks = [],
        ?ModelResolverInterface $modelResolver = null,
    ): SymfonyPlatformInterface {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber($modelResolver));
        $eventDispatcher->addSubscriber(new BeforeProviderRequestSubscriber($beforeProviderRequestHooks));

        return new Platform(
            providers: [new Provider(
                name: 'fake',
                modelClients: [$modelClient],
                resultConverters: [new FakeStreamResultConverter($streamFactory)],
                modelCatalog: new FallbackModelCatalog(),
                eventDispatcher: $eventDispatcher,
            )],
            eventDispatcher: $eventDispatcher,
        );
    }
}

final class FakeSymfonyModelClient implements ModelClientInterface
{
    public ?string $capturedModel = null;

    /** @var array<string, mixed> */
    public array $capturedOptions = [];

    /** @var array<string, mixed>|string|null */
    public array|string|null $capturedPayload = null;

    public function __construct(
        private readonly TokenUsageInterface $tokenUsage,
    ) {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function request(Model $model, array|string $payload, array $options = []): RawResultInterface
    {
        $this->capturedModel = $model->getName();
        $this->capturedPayload = $payload;
        $this->capturedOptions = $options;

        return new InMemoryRawResult(['token_usage' => $this->tokenUsage]);
    }
}

final readonly class FakeStreamResultConverter implements ResultConverterInterface
{
    /**
     * @param \Closure(): iterable<mixed> $streamFactory
     */
    public function __construct(
        private \Closure $streamFactory,
    ) {
    }

    public function supports(Model $model): bool
    {
        return true;
    }

    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        unset($result, $options);

        $streamFactory = $this->streamFactory;

        return new StreamResult((static function () use ($streamFactory): \Generator {
            foreach ($streamFactory() as $delta) {
                if ($delta instanceof TextDelta) {
                    yield $delta;
                }
            }
        })());
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        return new class implements TokenUsageExtractorInterface {
            public function extract(RawResultInterface $rawResult, array $options = []): ?TokenUsageInterface
            {
                unset($options);

                $data = $rawResult->getData();
                $tokenUsage = $data['token_usage'] ?? null;

                return $tokenUsage instanceof TokenUsageInterface ? $tokenUsage : null;
            }
        };
    }
}

final readonly class FakeTokenUsage implements TokenUsageInterface
{
    public function __construct(
        private ?int $promptTokens = null,
        private ?int $completionTokens = null,
        private ?int $cachedTokens = null,
        private ?int $cacheReadTokens = null,
        private ?int $cacheCreationTokens = null,
        private ?int $totalTokens = null,
    ) {
    }

    public function getPromptTokens(): ?int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): ?int
    {
        return $this->completionTokens;
    }

    public function getThinkingTokens(): ?int
    {
        return null;
    }

    public function getToolTokens(): ?int
    {
        return null;
    }

    public function getCachedTokens(): ?int
    {
        return $this->cachedTokens;
    }

    public function getCacheCreationTokens(): ?int
    {
        return $this->cacheCreationTokens;
    }

    public function getCacheReadTokens(): ?int
    {
        return $this->cacheReadTokens;
    }

    public function getRemainingTokens(): ?int
    {
        return null;
    }

    public function getRemainingTokensMinute(): ?int
    {
        return null;
    }

    public function getRemainingTokensMonth(): ?int
    {
        return null;
    }

    public function getTotalTokens(): ?int
    {
        return $this->totalTokens;
    }
}

final class ToggleCancellationToken implements CancellationTokenInterface
{
    private int $checks = 0;

    public function isCancellationRequested(): bool
    {
        ++$this->checks;

        return $this->checks > 1;
    }
}
