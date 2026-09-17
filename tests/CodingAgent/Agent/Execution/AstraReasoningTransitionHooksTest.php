<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Message\AgentMessage;
use Ineersa\CodingAgent\Agent\Execution\AstraReasoningTransitionRequestHook;
use Ineersa\CodingAgent\Agent\Execution\AstraReasoningTransitionTransformHook;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionMetadata;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Uid\Uuid;
use Symfony\Component\Uid\UuidV7;

final class AstraReasoningTransitionHooksTest extends IsolatedKernelTestCase
{
    private string $tempDir;
    private string $homeDir;
    private \Doctrine\ORM\EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('astra-transition-hooks', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: cyberpunk\n");
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testContainerWiresHooks(): void
    {
        $container = static::getContainer();

        $this->assertInstanceOf(
            AstraReasoningTransitionRequestHook::class,
            $container->get(AstraReasoningTransitionRequestHook::class),
        );
        $this->assertInstanceOf(
            AstraReasoningTransitionTransformHook::class,
            $container->get(AstraReasoningTransitionTransformHook::class),
        );
    }

    public function testUnchangedEffortDoesNotStampWhileChangeAndReturnReplayStablePrefix(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';

        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));
        $unchanged = $store->claimReasoningBaseline($sessionId, $modelRef, 'medium');
        $this->assertSame([
            'baseline' => 'medium',
            'update' => null,
            'last_emitted' => 'medium',
        ], $unchanged);

        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $transformHook = $this->createTransformHook($store);

        $firstUser = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'first']]);
        $secondUser = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'second']]);
        $history = [$firstUser, $secondUser];

        $toHigh = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertSame('high', $toHigh['update'] ?? null);

        $bag = new MessageBag(Message::ofUser('first'), Message::ofUser('second'));
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $marked = $transformHook->transformContext($history, null, $sessionId);
        $this->assertSame('high', $marked[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);

        $payload = $this->normalize($marked);
        $this->assertSame('configuration_update', $payload['input'][1]['type']);
        $this->assertSame('high', $payload['input'][1]['reasoning']['effort']);

        $session = $store->findSession($sessionId);
        $this->assertNotNull($session);
        $this->assertSame('high', $session->reasoningBaseline['last_emitted'] ?? null);
        $stillHigh = $store->claimReasoningBaseline($sessionId, $modelRef, 'high');
        $this->assertIsArray($stillHigh);
        $this->assertNull($stillHigh['update']);

        $thirdUser = new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'third']]);
        $extendedHistory = [...$marked, $thirdUser];
        $replayed = $transformHook->transformContext($extendedHistory, null, $sessionId);
        $extendedPayload = $this->normalize($replayed);
        $this->assertSame(
            \array_slice($extendedPayload['input'], 0, \count($payload['input'])),
            $payload['input'],
        );

        $toMedium = $store->claimReasoningBaseline($sessionId, $modelRef, 'medium');
        $this->assertSame('medium', $toMedium['update'] ?? null);
        $bagBack = new MessageBag(
            Message::ofUser('first'),
            Message::ofUser('second'),
            Message::ofUser('third'),
            Message::ofUser('fourth'),
        );
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bagBack],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'medium',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $withReturn = $transformHook->transformContext([
            ...$replayed,
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'fourth']]),
        ], null, $sessionId);
        $returnPayload = $this->normalize($withReturn);
        $this->assertSame('configuration_update', $returnPayload['input'][1]['type']);
        $this->assertSame('high', $returnPayload['input'][1]['reasoning']['effort']);
        $this->assertSame('configuration_update', $returnPayload['input'][4]['type']);
        $this->assertSame('medium', $returnPayload['input'][4]['reasoning']['effort']);

        $store->resetReasoningBaseline($sessionId);
        $this->assertSame([], $store->listReasoningTransitions($sessionId, $modelRef));
        $stripped = $transformHook->transformContext($withReturn, null, $sessionId);
        foreach ($stripped as $message) {
            $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::KEY, $message->metadata);
        }
    }

    public function testDuplicateIdenticalUserMessagesGetDistinctTransitionKeys(): void
    {
        $store = $this->createStore();
        $sessionId = $this->createSession($store, 'openai-codex/gpt-6-astra', 'medium');
        $modelRef = 'openai-codex/gpt-6-astra';
        $this->assertNull($store->claimReasoningBaseline($sessionId, $modelRef, 'medium'));

        $requestHook = new AstraReasoningTransitionRequestHook($store);
        $transformHook = $this->createTransformHook($store);

        $bag = new MessageBag(Message::ofUser('same'), Message::ofUser('same'));
        $requestHook->beforeProviderRequest(
            $modelRef,
            ['message_bag' => $bag],
            [
                CodexRequestBodyFactory::REASONING_UPDATE => 'high',
                'hatfield_run_id' => $sessionId,
                'hatfield_model_ref' => $modelRef,
            ],
        );

        $messages = [
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'same']]),
            new AgentMessage(role: 'user', content: [['type' => 'text', 'text' => 'same']]),
        ];
        $marked = $transformHook->transformContext($messages, null, $sessionId);

        $this->assertArrayNotHasKey(CodexReasoningTransitionMetadata::KEY, $marked[0]->metadata);
        $this->assertSame('high', $marked[1]->metadata[CodexReasoningTransitionMetadata::KEY] ?? null);

        $key0 = AstraReasoningTransitionTransformHook::messageKeyInHistory($messages, 0);
        $key1 = AstraReasoningTransitionTransformHook::messageKeyInHistory($messages, 1);
        $this->assertNotSame($key0, $key1);
    }

    /**
     * @param list<AgentMessage> $messages
     *
     * @return array{input: list<array<string, mixed>>}
     */
    private function normalize(array $messages): array
    {
        $bagMessages = [];
        foreach ($messages as $message) {
            $text = '';
            foreach ($message->content as $part) {
                if (\is_array($part) && \is_string($part['text'] ?? null)) {
                    $text .= $part['text'];
                }
            }
            $platform = Message::ofUser($text);
            $effort = $message->metadata[CodexReasoningTransitionMetadata::KEY] ?? null;
            if (\is_string($effort) && '' !== $effort) {
                $platform->getMetadata()->add(CodexReasoningTransitionMetadata::KEY, $effort);
            }
            $bagMessages[] = $platform;
        }

        /** @var array{input: list<array<string, mixed>>} $payload */
        $payload = CodexContract::create()->createRequestPayload(
            new CodexModel('gpt-6-astra'),
            new MessageBag(...$bagMessages),
            [],
        );

        return $payload;
    }

    private function createStore(): HatfieldSessionStore
    {
        return new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->entityManager,
            dispatcher: new EventDispatcher(),
        );
    }

    private function createTransformHook(HatfieldSessionStore $store): AstraReasoningTransitionTransformHook
    {
        $aiData = [
            'default_model' => 'openai-codex/gpt-6-astra',
            'default_reasoning' => 'medium',
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'compatibility' => ['thinking_format' => 'codex'],
                    'models' => [
                        'gpt-6-astra' => [
                            'name' => 'Astra',
                            'reasoning' => true,
                            'thinking_level_map' => ['low' => 'low', 'medium' => 'medium', 'high' => 'high'],
                            'compatibility' => ['supports_reasoning_configuration_updates' => true],
                        ],
                    ],
                ],
            ],
        ];
        $ai = AiConfig::optionalFromArray(['ai' => $aiData]);
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'cyberpunk'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: ['ai' => $aiData],
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new SettingsOverrideWriter($pathResolver, PropertyAccess::createPropertyAccessor(), new Filesystem());
        $selection = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $store, new \Psr\Log\NullLogger()),
            $homeWriter,
            $store,
        );

        return new AstraReasoningTransitionTransformHook(
            $selection,
            $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: [])),
            $store,
        );
    }

    private function createSession(HatfieldSessionStore $store, string $model, string $reasoning): string
    {
        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = $model;
        $entity->reasoning = $reasoning;
        $entity->providerCacheKey = UuidV7::v7()->toRfc4122();
        $this->entityManager->persist($entity);
        $this->entityManager->flush();

        $this->assertTrue(Uuid::isValid((string) $entity->providerCacheKey));

        return (string) $entity->id;
    }
}
