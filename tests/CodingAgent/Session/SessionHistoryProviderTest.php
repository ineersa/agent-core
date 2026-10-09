<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Session;

use Ineersa\AgentCore\Application\Pipeline\ToolExecutionEndPayloadCodec;
use Ineersa\AgentCore\Application\Replay\ReplayEventPreparer;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Contract\EventStoreInterface;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Message\AgentMessageNormalizer;
use Ineersa\AgentCore\Domain\Message\ToolCallResult;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\AgentCore\Tests\Support\PreparedEventStoreSeeder;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ProjectedSymfonyModelCatalog;
use Ineersa\CodingAgent\Session\FileRunSequenceAllocator;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Session\History\HistoryProjector;
use Ineersa\CodingAgent\Session\History\HistoryReplayFilter;
use Ineersa\CodingAgent\Session\Replay\SessionRunStateReplayService;
use Ineersa\CodingAgent\Session\SessionHistoryProvider;
use Ineersa\CodingAgent\Session\SessionRunEventStore;
use Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture;
use Ineersa\CodingAgent\Tests\Support\ChatGPTNativeReplayFixture;
use Ineersa\CodingAgent\Tests\TestCase\PerMethodIsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Factory;
use Symfony\AI\Platform\Bridge\OpenResponses\ResponsesModel;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

final class SessionHistoryProviderTest extends PerMethodIsolatedKernelTestCase
{
    public function testColdCanonicalSessionReplayPreservesNativeItemsAndCacheIdentity(): void
    {
        $container = self::getContainer();
        $em = $container->get('doctrine.orm.default_entity_manager');
        $entity = new HatfieldSession();
        $entity->cwd = (string) getcwd();
        $entity->model = 'openai-codex/gpt-5.5';
        $em->persist($entity);
        $em->flush();
        $runId = (string) $entity->id;
        $key = $entity->providerCacheKey;
        $bodies = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$bodies): MockResponse {
            $bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return ChatGPTNativeReplayFixture::response(1 === \count($bodies));
        });
        $store = $container->get(EventStoreInterface::class);
        $user = new AgentMessage('user', [['type' => 'text', 'text' => 'Read both fixtures']]);
        $result = $this->adapter($http, $container->get(HatfieldSessionStore::class))->invoke(new ModelInvocationRequest('', new ModelInvocationInput(runId: $runId, messages: [$user])));
        $this->assertNull($result->error);
        $this->assertNotNull($result->assistantMessage);
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($runId, 0, 'run_started', ['payload' => ['messages' => [$user->toArray()]]]));
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($runId, 1, 'turn_advanced', ['turn_no' => 1, 'step_id' => 'read']));
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($runId, 1, 'llm_step_completed', ['step_id' => 'read', 'model' => 'openai-codex/gpt-5.5', 'assistant_message' => (new AgentMessageNormalizer())->assistantMessagePayload($result->assistantMessage)]));
        $codec = new ToolExecutionEndPayloadCodec(AttributeSerializerValidatorTestFactory::serializer());
        foreach (['call_one', 'call_two'] as $index => $id) {
            PreparedEventStoreSeeder::append($store, RunEvent::forAppend($runId, 1, 'tool_execution_end', $codec->toEventPayload(new ToolCallResult(runId: $runId, turnNo: 1, stepId: 'read', attempt: 1, idempotencyKey: $id, toolCallId: $id.'|fc_'.$id, orderIndex: $index, result: ['tool_name' => 'read_file', 'content' => [['type' => 'text', 'text' => 'contents']]]))));
        }
        PreparedEventStoreSeeder::append($store, RunEvent::forAppend($runId, 1, 'tool_batch_committed', ['count' => 2]));
        $this->assertFileExists(getcwd().'/.hatfield/sessions/'.$runId.'/events.jsonl');
        // Discard both the event-store instance and ORM identity map. Nothing from the streamed result feeds the next request.
        $em->clear();
        $sessions = new HatfieldSessionStore($container->get(AppConfig::class), $em, new EventDispatcher());
        $cold = new SessionRunEventStore($sessions, new \Ineersa\AgentCore\Schema\EventPayloadNormalizer(), new LockFactory(new FlockStore()), new NullLogger(), new FileRunSequenceAllocator());
        $history = (new SessionHistoryProvider($cold, new HistoryProjector()))->forSession($runId);
        $this->assertSame('Read both fixtures', $history->prompts[0]->promptText);
        $replay = new SessionRunStateReplayService($cold, new NullLogger(), $container->get(RunStateReducer::class), new ReplayEventPreparer(), new HistoryReplayFilter(new HistoryProjector()));
        $state = $replay->rebuildIfStale(RunState::queued($runId), $runId)->rebuiltState;
        $this->assertNotNull($state);
        $this->assertCount(4, $state->messages);
        $next = $this->adapter($http, $sessions)->invoke(new ModelInvocationRequest('', new ModelInvocationInput(runId: $runId, messages: $state->messages)));
        $this->assertNull($next->error);
        $this->assertSame(ChatGPTNativeReplayFixture::items(), ChatGPTNativeReplayFixture::replayedItems($bodies[1]));
        $this->assertSame($key, $bodies[0]['prompt_cache_key']);
        $this->assertSame($key, $bodies[1]['prompt_cache_key']);
        $this->assertSame(['call_one', 'call_two'], array_column(array_values(array_filter($bodies[1]['input'], static fn (array $item): bool => 'function_call_output' === ($item['type'] ?? null))), 'call_id'));
    }

    private function adapter(MockHttpClient $http, HatfieldSessionStore $sessions): LlmPlatformAdapter
    {
        $catalog = new HatfieldModelCatalog(new AiConfig(defaultModel: 'openai-codex/gpt-5.5', providers: ['openai-codex' => new AiProviderConfig('openai-codex', type: 'chatgpt', models: ['gpt-5.5' => new AiModelDefinition('gpt-5.5', toolCalling: true, reasoning: true)])]));
        $config = new AppConfig(new TuiConfig('default'), new LoggingConfig(), catalog: $catalog);
        $selection = new ModelSelectionService($config, new ModelResolver($config, $sessions, new NullLogger()), self::getContainer()->get(SettingsOverrideWriter::class), $sessions);
        $resolver = new SessionAwareModelResolver($selection, $catalog, $sessions, self::getContainer()->get(DeferredSubagentChildRepository::class));
        $record = ChatGPTAuthFixture::record();
        $storage = $this->createStub(AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static fn (callable $update) => $update($record));
        $provider = Factory::createProvider(ChatGPTAuthFixture::service($storage, $http), $http, new ProjectedSymfonyModelCatalog(['gpt-5.5' => new AiModelDefinition('gpt-5.5')], ResponsesModel::class, 'openai-codex'), name: 'openai-codex');

        return new LlmPlatformAdapter(new NullRunOperationalStatusReader(), new AgentMessageConverter(), new DynamicToolDescriptionProcessor(), new Platform([$provider]), [], [], null, null, new NullLogger(), AttributeSerializerValidatorTestFactory::denormalizer(), $resolver);
    }
}
