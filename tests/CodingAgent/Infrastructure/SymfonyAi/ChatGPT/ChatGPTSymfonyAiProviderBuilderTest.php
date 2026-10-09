<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi\ChatGPT;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\Retry\LlmRequestRetryPolicy;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ChatGPT\ChatGPTSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionLimitException;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Exception\SubscriptionPolicyException;
use Symfony\AI\Platform\Platform;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class ChatGPTSymfonyAiProviderBuilderTest extends TestCase
{
    public function testFreshProviderBuildDoesNotRequireOrReadSavedGrant(): void
    {
        $storage = $this->createMock(AuthStorageInterface::class);
        $storage->expects($this->never())->method('load');
        $storage->expects($this->never())->method('update');
        $client = new MockHttpClient(static function (): never { self::fail('Boot must not authenticate.'); });
        $builder = new ChatGPTSymfonyAiProviderBuilder(ChatGPTAuthFixture::service($storage, $client), $this->createStub(EventDispatcherInterface::class));
        $config = $this->config();
        $this->assertTrue($builder->supports($config));
        $this->assertFalse($builder->supports(new AiProviderConfig('old', type: 'codex')));
        $this->assertSame('gpt-6.1-sol', $builder->build($config, $client)->getModelCatalog()->getModel('openai-codex/gpt-6.1-sol')->getName());
    }

    public function testToolAndEncryptedReasoningRoundTripUsesFullHistoryAndKeepsIdentities(): void
    {
        $bodies = [];
        $first = [
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_one', 'summary' => []]],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_one', 'encrypted_content' => 'opaque-reasoning', 'summary' => []]],
            ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'fc_one', 'call_id' => 'call_one', 'name' => 'read_file', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_one', 'output_index' => 1, 'delta' => '{"path":"fixture.txt"}'],
            ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'fc_one', 'call_id' => 'call_one', 'name' => 'read_file', 'arguments' => '{"path":"fixture.txt"}']],
            ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => []]],
        ];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $first): MockResponse {
            self::assertSame('https://api.openai.com/v1/responses', $url);
            self::assertSame(0.0, $options['max_duration']);
            self::assertSame(300.0, $options['timeout']);
            $bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);
            $events = 1 === \count($bodies) ? $first : [['type' => 'response.output_text.delta', 'delta' => 'done'], ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => []]]];

            return self::response($events);
        });
        $client = $client->withOptions(['max_duration' => 120]);
        $adapter = $this->adapter($client);
        $user = new AgentMessage('user', [['type' => 'text', 'text' => 'Read fixture.txt']]);
        $options = new ModelInvocationOptions(extraOptions: [
            'tools' => [['type' => 'function', 'name' => 'read_file', 'description' => 'Read a local fixture', 'parameters' => ['type' => 'object', 'properties' => ['path' => ['type' => 'string']], 'required' => ['path']]]],
            'reasoning' => ['effort' => 'high'], 'previous_response_id' => 'forbidden', 'temperature' => 0.5,
            'max_output_tokens' => 10, 'hatfield_run_id' => 'internal', 'prompt_cache_key' => 'stable-cache-key',
        ]);
        $result = $adapter->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(runId: 'synthetic-run', messages: [$user]), $options));
        $this->assertNull($result->error);
        $this->assertNotNull($result->assistantMessage);
        $this->assertSame('call_one|fc_one', $result->assistantMessage->getToolCalls()[0]->getId());
        $assistant = (new AgentMessageNormalizer())->assistantMessage($result->assistantMessage, 'openai-codex/gpt-6.1-sol');
        $assistant = AgentMessage::fromPayload($assistant->toArray());
        $tool = new AgentMessage('tool', [['type' => 'text', 'text' => 'fixture contents']], toolCallId: 'call_one|fc_one', toolName: 'read_file');
        $next = $adapter->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(runId: 'synthetic-run', messages: [$user, $assistant, $tool]), $options));
        $this->assertNull($next->error);
        $this->assertSame('done', $next->assistantMessage?->asText());
        foreach ($bodies as $body) {
            $this->assertFalse($body['store']);
            $this->assertTrue($body['stream']);
            foreach (['previous_response_id', 'temperature', 'max_output_tokens', 'hatfield_run_id', 'tools'] as $key) {
                $this->assertArrayNotHasKey($key, $body);
            }
            $this->assertSame('stable-cache-key', $body['prompt_cache_key']);
            $this->assertSame('additional_tools', $body['input'][0]['type']);
            $this->assertSame('read_file', $body['input'][0]['tools'][0]['name']);
            $this->assertFalse($body['input'][0]['tools'][0]['strict']);
        }
        $items = array_column($bodies[1]['input'], null, 'type');
        $this->assertSame('opaque-reasoning', $items['reasoning']['encrypted_content']);
        $this->assertSame('fc_one', $items['function_call']['id']);
        $this->assertSame('call_one', $items['function_call']['call_id']);
        $this->assertSame('call_one', $items['function_call_output']['call_id']);
        $this->assertSame('fixture contents', $items['function_call_output']['output']);
    }

    public function testInterruptedUnfinishedToolStreamCannotDispatchOrPersistCompleteTool(): void
    {
        $client = new MockHttpClient(self::response([
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_partial', 'call_id' => 'call_partial', 'name' => 'read_file', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'item_id' => 'fc_partial', 'output_index' => 0, 'delta' => '{"path":'],
        ]));
        $result = $this->adapter($client)->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'read']])])));
        $this->assertNotNull($result->error);
        $this->assertSame([], $result->assistantMessage?->getToolCalls() ?? []);
    }

    #[DataProvider('subscriptionFailureProvider')]
    public function testSubscriptionFailureIsTerminalWithoutReplay(string $code, string $exceptionClass, bool $stream): void
    {
        $calls = 0;
        $client = new MockHttpClient(static function () use (&$calls, $code, $stream): MockResponse {
            ++$calls;

            $error = ['code' => $code, 'message' => 'sensitive-body'];

            return $stream
                ? self::response([['type' => 'response.failed', 'response' => ['status' => 'failed', 'error' => $error]]])
                : new MockResponse(json_encode(['error' => $error], \JSON_THROW_ON_ERROR), ['http_code' => 403]);
        });
        $result = $this->adapter($client, maxRetries: 2)->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'hello']])])));
        $this->assertSame(1, $calls);
        $this->assertNotNull($result->error);
        $this->assertSame($exceptionClass, $result->error['type']);
        $this->assertFalse($result->error['retryable']);
        $this->assertStringNotContainsString('sensitive-body', $result->error['message']);
    }

    public static function subscriptionFailureProvider(): array
    {
        $cases = [];
        foreach (['insufficient_quota', 'subscription_sharing_usage_limit_exceeded', 'subscription_sharing_user_not_eligible', 'subscription_sharing_unsupported_capability', 'subscription_sharing_route_not_supported', 'chatpass_v2_scope_not_authorized', 'chatpass_v2_invalid_authorization_context', 'subscription_sharing_invalid_user'] as $code) {
            $exceptionClass = \in_array($code, ['insufficient_quota', 'subscription_sharing_usage_limit_exceeded'], true) ? SubscriptionLimitException::class : SubscriptionPolicyException::class;
            foreach ([false, true] as $stream) {
                $cases[$code.($stream ? '-sse' : '-http')] = [$code, $exceptionClass, $stream];
            }
        }

        return $cases;
    }

    public function testCancelBeforeFirstDeltaAbortsWithoutPersistingTools(): void
    {
        $token = new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };
        $client = new MockHttpClient(static function (): never { self::fail('A pre-cancelled stream must not open HTTP.'); });
        $result = $this->adapter($client)->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'hello']])]), new ModelInvocationOptions(cancelToken: $token)));
        $this->assertSame('aborted', $result->stopReason);
        $this->assertNull($result->error);
        $this->assertNull($result->assistantMessage);
    }

    public function testCancellationDuringSilentTransportWaitDropsUnconsumedText(): void
    {
        $token = new class implements \Ineersa\AgentCore\Contract\Hook\CancellationTokenInterface {
            public bool $requested = false;

            public function isCancellationRequested(): bool
            {
                return $this->requested;
            }
        };
        // The transport generator is the barrier: cancellation is requested only
        // after a buffered body chunk, then an empty chunk represents a silent wait.
        $body = (static function () use ($token): \Generator {
            yield 'data: {"type":"response.output_text.delta","delta":"visible"}'."\n\n";
            $token->requested = true;
            yield '';
            self::fail('No body may be consumed after cancellation.');
        })();
        $response = new MockResponse($body, ['response_headers' => ['content-type: text/event-stream']]);
        $result = $this->adapter(new MockHttpClient($response))->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(messages: [new AgentMessage('user', [['type' => 'text', 'text' => 'hello']])]), new ModelInvocationOptions(cancelToken: $token)));
        $this->assertSame('aborted', $result->stopReason);
        $this->assertNull($result->error);
        $this->assertNull($result->assistantMessage, 'Unconsumed buffered text must not become a canonical response.');
        $this->assertSame([], $result->assistantMessage?->getToolCalls() ?? []);
        $this->assertTrue($token->requested, 'The transport-wait barrier must request cancellation.');
    }

    #[DataProvider('nativeMessageBoundaryProvider')]
    public function testNativeItemsSurviveCanonicalEventSaveAndColdReplay(bool $terminalMessages): void
    {
        $reasoning = static fn (string $id): array => ['type' => 'reasoning', 'id' => $id, 'encrypted_content' => 'encrypted-'.$id, 'summary' => []];
        $call = static fn (string $id): array => ['arguments' => '{}', 'call_id' => $id, 'name' => 'read_file', 'type' => 'function_call', 'id' => 'fc_'.$id];
        $message = static fn (string $id, string $phase, string $text): array => ['type' => 'message', 'id' => $id, 'role' => 'assistant', 'phase' => $phase, 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []], ['type' => 'output_text', 'text' => '', 'annotations' => []]]];
        $expected = [$message('msg_prefix', 'commentary', 'same'), $reasoning('rs_one'), $call('call_one'), $reasoning('rs_two'), $call('call_two'), $message('msg_one', 'commentary', 'same'), $message('msg_two', 'commentary', 'same'), $message('msg_final', 'final_answer', 'same'), $message('msg_empty', 'final_answer', '')];
        $events = [];
        foreach ($expected as $index => $item) {
            if ($terminalMessages && 'message' === $item['type']) {
                continue;
            }
            $events[] = ['type' => 'response.output_item.added', 'output_index' => $index, 'item' => $item];
            if ('message' === $item['type'] && 'msg_empty' !== $item['id']) {
                $events[] = ['type' => 'response.output_text.delta', 'delta' => 'same'];
            }
            $events[] = ['type' => 'response.output_item.done', 'output_index' => $index, 'item' => $item];
        }
        $events[] = ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => $terminalMessages ? $expected : []]];
        $bodies = [];
        $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies, $events): MockResponse {
            $bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return self::response(1 === \count($bodies) ? $events : [['type' => 'response.output_text.delta', 'delta' => 'done'], ['type' => 'response.completed', 'response' => ['status' => 'completed', 'output' => []]]]);
        });
        $user = new AgentMessage('user', [['type' => 'text', 'text' => 'Read local fixtures']]);
        $result = $this->adapter($client)->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(messages: [$user])));
        $this->assertNull($result->error);
        $this->assertNotNull($result->assistantMessage);
        $directory = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createProjectTempDir('chatgpt-cold-replay');
        try {
            $createStore = function () use ($directory): \Ineersa\CodingAgent\Session\SessionRunEventStore {
                $sessions = new \Ineersa\CodingAgent\Session\HatfieldSessionStore(
                    new \Ineersa\CodingAgent\Config\AppConfig(new \Ineersa\CodingAgent\Config\TuiConfig('default'), new \Ineersa\CodingAgent\Config\LoggingConfig(), cwd: $directory),
                    $this->createStub(\Doctrine\ORM\EntityManagerInterface::class),
                    new \Symfony\Component\EventDispatcher\EventDispatcher(),
                );

                return new \Ineersa\CodingAgent\Session\SessionRunEventStore($sessions, new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(), new \Symfony\Component\Lock\LockFactory(new \Symfony\Component\Lock\Store\FlockStore()), new NullLogger(), new \Ineersa\CodingAgent\Session\FileRunSequenceAllocator());
            };
            $payload = (new AgentMessageNormalizer())->assistantMessagePayload($result->assistantMessage);
            \Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder::append($createStore(), \Ineersa\AgentCore\Domain\Event\RunEvent::forAppend(runId: 'fixture', turnNo: 1, type: 'llm_step_completed', payload: ['assistant_message' => $payload, 'model' => 'openai-codex/gpt-6.1-sol']));
            $this->assertFileExists($directory.'/.hatfield/sessions/fixture/events.jsonl');
            $event = $createStore()->allFor('fixture')[0];
            $assistant = (new \Ineersa\AgentCore\Application\Replay\ReplayAssistantMessageFactory())->create($event->payload['assistant_message'] + ['model' => $event->payload['model']]);
            $this->assertNotNull($assistant);
            $tools = [];
            foreach (['call_one', 'call_two'] as $id) {
                $tools[] = new AgentMessage('tool', [['type' => 'text', 'text' => 'contents']], toolCallId: $id.'|fc_'.$id, toolName: 'read_file');
            }
            $next = $this->adapter($client)->invoke(new ModelInvocationRequest('openai-codex/gpt-6.1-sol', new ModelInvocationInput(messages: [$user, $assistant, ...$tools])));
            $this->assertNull($next->error);
            $native = array_values(array_filter($bodies[1]['input'], static fn (array $item): bool => \in_array($item['type'] ?? null, ['reasoning', 'function_call', 'message'], true) && 'user' !== ($item['role'] ?? null)));
            $this->assertSame($expected, $native);
            $foreign = (new AgentMessageConverter())->toMessageBagForTarget([$assistant], 'foreign/model')->getMessages()[0];
            $this->assertInstanceOf(\Symfony\AI\Platform\Message\AssistantMessage::class, $foreign);
            foreach ($foreign->getContent() as $part) {
                if ($part instanceof \Symfony\AI\Platform\Message\Content\Text) {
                    $this->assertNull($part->getSignature());
                }
            }
        } finally {
            \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public static function nativeMessageBoundaryProvider(): array
    {
        return ['streamed' => [false], 'terminal-only' => [true]];
    }

    private function config(): AiProviderConfig
    {
        return new AiProviderConfig('openai-codex', type: 'chatgpt', models: ['gpt-6.1-sol' => new AiModelDefinition('gpt-6.1-sol', toolCalling: true, reasoning: true)]);
    }

    private function adapter(MockHttpClient $client, int $maxRetries = 0): LlmPlatformAdapter
    {
        $record = ChatGPTAuthFixture::record();
        $storage = $this->createStub(AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static fn (callable $update) => $update($record));
        $builder = new ChatGPTSymfonyAiProviderBuilder(ChatGPTAuthFixture::service($storage, $client), $this->createStub(EventDispatcherInterface::class));

        return new LlmPlatformAdapter(new NullRunOperationalStatusReader(), new AgentMessageConverter(), new DynamicToolDescriptionProcessor(), new Platform((new \Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory(
            new \Ineersa\CodingAgent\Config\AppConfig(new \Ineersa\CodingAgent\Config\TuiConfig('default'), new \Ineersa\CodingAgent\Config\LoggingConfig(), catalog: new \Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog(new \Ineersa\CodingAgent\Config\Ai\AiConfig(providers: ['openai-codex' => $this->config()]))),
            $this->createStub(EventDispatcherInterface::class), [$builder], httpClient: $client,
        ))->createProviders()), [], [], null, null, new NullLogger(), AttributeSerializerValidatorTestFactory::denormalizer(), requestRetryPolicy: new LlmRequestRetryPolicy(maxRetries: $maxRetries, baseDelayMs: 0));
    }

    /** @param list<array<string, mixed>> $events */
    private static function response(array $events): MockResponse
    {
        $body = '';
        foreach ($events as $event) {
            $body .= 'data: '.json_encode($event, \JSON_THROW_ON_ERROR)."\n\n";
        }

        return new MockResponse($body, ['response_headers' => ['content-type: text/event-stream']]);
    }
}
