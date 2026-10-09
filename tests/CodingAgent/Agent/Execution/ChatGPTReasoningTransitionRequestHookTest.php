<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelInvocationOptions;
use Ineersa\AgentCore\Domain\Model\ModelInvocationRequest;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderCompatibilityRequestShaper;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ProviderRequestPreparer;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ReasoningOptionsFeatureShaper;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\NullRunOperationalStatusReader;
use Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionRequestHook;
use Ineersa\CodingAgent\Agent\Execution\ChatGPTReasoningTransitionTransformHook;
use Ineersa\CodingAgent\Agent\Execution\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\DeferredSubagentChildRepository;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\ChatGPT\ChatGPTSymfonyAiProviderBuilder;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\ChatGPTAuthFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Bridge\OpenAIChatGPT\Auth\AuthStorageInterface;
use Symfony\AI\Platform\Platform;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Uid\Uuid;

final class ChatGPTReasoningTransitionRequestHookTest extends IsolatedKernelTestCase
{
    private string $directory;
    private string $sessionId;
    private AppConfig $config;
    private HatfieldSessionStore $sessions;
    /** @var list<array<string, mixed>> */
    private array $bodies = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = TestDirectoryIsolation::createProjectTempDir('chatgpt-effort');
        $ai = AiConfig::optionalFromArray(['ai' => ['default_model' => 'openai-codex/gpt-6-sol', 'default_reasoning' => 'low', 'providers' => ['openai-codex' => ['type' => 'chatgpt', 'enabled' => true, 'compatibility' => ['thinking_format' => 'chatgpt', 'supports_reasoning_configuration_updates' => true], 'models' => ['gpt-6-sol' => ['reasoning' => true, 'tool_calling' => true, 'thinking_level_map' => ['low' => 'low', 'high' => 'high'], 'compatibility' => ['supports_reasoning_configuration_updates' => true]], 'ordinary' => ['reasoning' => true, 'thinking_level_map' => ['low' => 'low', 'high' => 'high']]]]]]]);
        $this->assertNotNull($ai);
        $this->config = new AppConfig(new TuiConfig('default'), new LoggingConfig(), ai: $ai, catalog: new HatfieldModelCatalog($ai), cwd: $this->directory);
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $entity = new HatfieldSession();
        $entity->cwd = $this->directory;
        $entity->model = 'openai-codex/gpt-6-sol';
        $entity->reasoning = 'low';
        $em->persist($entity);
        $em->flush();
        $this->sessionId = (string) $entity->id;
        $this->sessions = new HatfieldSessionStore($this->config, $em, new EventDispatcher());
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->directory);
        parent::tearDown();
    }

    public function testSwitchesResumeSummaryForkAndHistoryRewriteHaveIndependentEpochs(): void
    {
        $messages = [self::user('first')];
        $this->invoke($messages, 'low');
        $this->assertSame('low', $this->bodies[0]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[0]));
        $messages[] = self::user('second');
        $this->invoke($messages, 'high');
        $this->assertSame('low', $this->bodies[1]['reasoning']['effort']);
        $this->assertSame(['high'], self::controls($this->bodies[1]));
        $this->assertSame('configuration_update', $this->bodies[1]['input'][1]['type']);
        $this->assertSame('second', $this->bodies[1]['input'][2]['content']);
        $this->invoke($messages, 'high');
        $this->assertSame($this->bodies[1], $this->bodies[2]);
        $messages[] = self::user('third');
        $this->invoke($messages, 'low');
        $this->assertSame(['high', 'low'], self::controls($this->bodies[3]));
        $this->assertSame('low', $this->bodies[3]['reasoning']['effort']);
        $this->assertSame($this->bodies[0]['prompt_cache_key'], $this->bodies[3]['prompt_cache_key']);
        // Drop the ORM identity map and construct a new store/adapter, as a resumed worker does.
        $em = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $em->clear();
        $this->sessions = new HatfieldSessionStore($this->config, $em, new EventDispatcher());
        $this->invoke($messages, 'low');
        $this->assertSame($this->bodies[3], $this->bodies[4]);
        $before = $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline;
        $this->invoke($messages, 'high', explicit: true);
        $this->assertSame([], self::controls($this->bodies[5]));
        $this->assertSame('high', $this->bodies[5]['reasoning']['effort']);
        $this->assertSame($before, $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline);
        $childId = Uuid::v7()->toRfc4122();
        $this->invoke($messages, 'high', explicit: true, runId: $childId);
        $this->assertSame([], self::controls($this->bodies[6]));
        $this->assertSame($childId, $this->bodies[6]['prompt_cache_key']);
        $this->assertNotSame($this->bodies[0]['prompt_cache_key'], $this->bodies[6]['prompt_cache_key']);
        $this->assertSame($before, $em->find(HatfieldSession::class, (int) $this->sessionId)->reasoningBaseline);
        // Discard both switch anchors: the retained request claims a fresh selected-effort baseline.
        $this->invoke([self::user('first')], 'high');
        $this->assertSame('high', $this->bodies[7]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[7]));
        // Accepted compaction replaces the root; no old transition survives the summary.
        $this->invoke([self::user('compacted summary')], 'low');
        $this->assertSame('low', $this->bodies[8]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[8]));
        $this->sessions->updateMetadata($this->sessionId, ['model' => 'openai-codex/ordinary']);
        $this->invoke([self::user('ordinary')], 'high');
        $this->assertSame('high', $this->bodies[9]['reasoning']['effort']);
        $this->assertSame([], self::controls($this->bodies[9]));
    }

    /** @param list<AgentMessage> $messages */
    private function invoke(array $messages, string $effort, bool $explicit = false, ?string $runId = null): void
    {
        if (!$explicit) {
            $this->sessions->updateMetadata($this->sessionId, ['reasoning' => $effort]);
        }
        $paths = new SettingsPathResolver($this->directory, $this->directory.'/home');
        $writer = new SettingsOverrideWriter($paths, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $selection = new ModelSelectionService($this->config, new ModelResolver($this->config, $this->sessions, new NullLogger()), $writer, $this->sessions);
        $catalog = $this->config->catalog;
        $this->assertNotNull($catalog);
        $resolver = new SessionAwareModelResolver($selection, $catalog, $this->sessions, static::getContainer()->get(DeferredSubagentChildRepository::class));
        $http = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            $this->bodies[] = json_decode($options['body'], true, flags: \JSON_THROW_ON_ERROR);

            return new MockResponse("data: {\"type\":\"response.output_text.delta\",\"delta\":\"done\"}\n\ndata: {\"type\":\"response.completed\",\"response\":{\"status\":\"completed\",\"output\":[]}}\n\n", ['response_headers' => ['content-type: text/event-stream']]);
        });
        $record = ChatGPTAuthFixture::record();
        $storage = $this->createStub(AuthStorageInterface::class);
        $storage->method('update')->willReturnCallback(static fn (callable $update) => $update($record));
        $builder = new ChatGPTSymfonyAiProviderBuilder(ChatGPTAuthFixture::service($storage, $http), new EventDispatcher());
        $provider = $catalog->getProvider('openai-codex');
        $this->assertNotNull($provider);
        $adapter = new LlmPlatformAdapter(new NullRunOperationalStatusReader(), new AgentMessageConverter(), new DynamicToolDescriptionProcessor(), new Platform([$builder->build($provider, $http)]), [new ChatGPTReasoningTransitionTransformHook($selection, $catalog, $this->sessions)], [], null, null, new NullLogger(), AttributeSerializerValidatorTestFactory::denormalizer(), $resolver, new ProviderRequestPreparer([new ChatGPTReasoningTransitionRequestHook($this->sessions)], new ProviderCompatibilityRequestShaper([new ReasoningOptionsFeatureShaper()])));
        $result = $adapter->invoke(new ModelInvocationRequest($explicit ? 'openai-codex/gpt-6-sol' : '', new ModelInvocationInput(runId: $runId ?? $this->sessionId, messages: $messages), new ModelInvocationOptions(extraOptions: $explicit ? ['thinking_level' => $effort] : [])));
        $this->assertNull($result->error);
    }

    private static function user(string $text): AgentMessage
    {
        return new AgentMessage('user', [['type' => 'text', 'text' => $text]]);
    }

    /** @param array<string, mixed> $body */
    private static function controls(array $body): array
    {
        return array_values(array_map(static fn (array $item): string => $item['reasoning']['effort'], array_filter($body['input'], static fn (array $item): bool => 'configuration_update' === ($item['type'] ?? null))));
    }
}
