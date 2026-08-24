<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\Subagent\ChildRun;

use Doctrine\ORM\EntityManagerInterface;
use Ineersa\AgentCore\Application\Pipeline\AdvanceRunHandler;
use Ineersa\AgentCore\Application\Pipeline\CommandMailboxPolicy;
use Ineersa\AgentCore\Application\Replay\RunStateReducer;
use Ineersa\AgentCore\Domain\Event\EventFactory;
use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Domain\Message\AdvanceRun;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Model\ModelInvocationInput;
use Ineersa\AgentCore\Domain\Model\ModelResolutionOptions;
use Ineersa\AgentCore\Domain\Run\RunState;
use Ineersa\AgentCore\Infrastructure\Storage\InMemoryRunStore;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\AgentMessageConverter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\DynamicToolDescriptionProcessor;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\LlmPlatformAdapter;
use Ineersa\AgentCore\Infrastructure\SymfonyAi\ModelResolverRoutingSubscriber;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\Fake\FakeStreamResultConverter;
use Ineersa\AgentCore\Tests\Support\Fake\FakeSymfonyModelClient;
use Ineersa\AgentCore\Tests\Support\Fake\FakeTokenUsage;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionAwareModelResolver;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * P0 cost-safety regression for session-33 model drift.
 *
 * Thesis: a child run whose run_started metadata.model is DeepSeek must invoke
 * DeepSeek even when its UUID numeric prefix collides with a normal session
 * whose model/default is Codex.  Execution identity is resolved at the provider
 * boundary by the production {@see SessionAwareModelResolver}: RunStarted child
 * metadata wins over colliding session metadata, and the scheduler carries no
 * model snapshot.
 */
final class ChildRunModelRoutingProvenanceRegressionTest extends IsolatedKernelTestCase
{
    private const string CHILD_RUN_ID = '3d451a76-e371-5ece-b9ca-8769167d85e4';
    private const string CHILD_MODEL = 'deepseek/deepseek-v4-flash';
    private const string DEFAULT_CODEX_MODEL = 'openai-codex/gpt-5.6-sol';
    private const string COLLIDING_SESSION_MODEL = 'openai-codex/gpt-5.6-luna';

    private EntityManagerInterface $entityManager;
    private string $tempDir;
    private string $homeDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entityManager = self::getContainer()->get('doctrine.orm.default_entity_manager');
        \assert($this->entityManager instanceof EntityManagerInterface);

        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('child-model-routing', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->homeDir.'/.hatfield', 0777, true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        parent::tearDown();
    }

    public function testChildConfiguredModelWinsThroughProductionResolverAndProviderBoundary(): void
    {
        // Seed normal sessions 1..3 so (int) '3d451...' resolves to session 3.
        $collidingSessionId = null;
        for ($i = 0; $i < 3; ++$i) {
            $session = new HatfieldSession();
            $session->cwd = $this->tempDir.'/project';
            if (2 === $i) {
                $session->model = self::COLLIDING_SESSION_MODEL;
            }
            $this->entityManager->persist($session);
            $this->entityManager->flush();
            if (2 === $i) {
                $collidingSessionId = (string) $session->id;
            }
        }
        $this->assertSame('3', $collidingSessionId);
        $this->assertSame(3, (int) self::CHILD_RUN_ID);

        // Seed the child's RunStarted metadata (production reader source).
        $eventStore = new InMemoryEventStore();
        $eventStore->seed($this->childRunStartedEvent());
        $reader = new SubagentRunMetadataReader(
            $eventStore,
            AttributeSerializerValidatorTestFactory::denormalizer(),
        );

        $resolver = $this->createResolver($reader);

        // Control: the colliding numeric session still resolves its own metadata.
        $sessionResolved = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: (string) $collidingSessionId),
            new ModelResolutionOptions(),
        );
        $this->assertSame(self::COLLIDING_SESSION_MODEL, $sessionResolved->model);

        // The child's configured model wins over the colliding session and default.
        $childResolved = $resolver->resolve(
            '',
            new MessageBag(),
            new ModelInvocationInput(runId: self::CHILD_RUN_ID),
            new ModelResolutionOptions(),
        );
        $this->assertSame(self::CHILD_MODEL, $childResolved->model);
        $this->assertSame('medium', $childResolved->reasoning);

        // Provider boundary: the production resolver drives the adapter, so the
        // actual Symfony provider request model is DeepSeek, not the colliding
        // session model and not the Codex default.
        $client = new FakeSymfonyModelClient(new FakeTokenUsage(promptTokens: 1, completionTokens: 1, totalTokens: 2));
        $adapter = $this->createAdapter($resolver, $client);

        $response = $adapter->invoke(new \Ineersa\AgentCore\Domain\Model\ModelInvocationRequest(
            model: '',
            input: new ModelInvocationInput(
                runId: self::CHILD_RUN_ID,
                turnNo: 1,
                stepId: 'llm-child-1',
            ),
        ));

        $this->assertSame(self::CHILD_MODEL, $client->capturedModel);
        $this->assertSame(self::CHILD_MODEL, $response->model);
        $this->assertSame('medium', $response->reasoning);
        $this->assertNotSame(self::DEFAULT_CODEX_MODEL, $client->capturedModel);
        $this->assertNotSame(self::COLLIDING_SESSION_MODEL, $client->capturedModel);

        // Scheduler effect carries no model snapshot — the provider boundary
        // is the single resolution point.
        $state = (new RunStateReducer())->replay(
            RunState::queued(self::CHILD_RUN_ID),
            [$this->childRunStartedEvent()],
        );
        $this->assertSame(self::CHILD_MODEL, $state->model, 'Historical projection stays intact.');

        $handler = new AdvanceRunHandler(
            commandMailboxPolicy: self::getContainer()->get(CommandMailboxPolicy::class),
            eventFactory: self::getContainer()->get(EventFactory::class),
        );
        $result = $handler->handle(
            new AdvanceRun(
                runId: self::CHILD_RUN_ID,
                turnNo: 0,
                stepId: 'advance-1',
                attempt: 1,
                idempotencyKey: 'ik-advance-1',
            ),
            $state,
        );

        $effect = null;
        foreach ($result->effects as $candidate) {
            if ($candidate instanceof ExecuteLlmStep) {
                $effect = $candidate;
                break;
            }
        }
        $this->assertInstanceOf(ExecuteLlmStep::class, $effect);
        $this->assertFalse(property_exists($effect, 'model'), 'Scheduling must not snapshot a model onto ExecuteLlmStep.');
    }

    private function childRunStartedEvent(): RunEvent
    {
        return new RunEvent(
            runId: self::CHILD_RUN_ID,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'step_id' => 'start-child',
                'payload' => [
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => '1',
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_child1',
                        ],
                        'model' => self::CHILD_MODEL,
                        'reasoning' => 'medium',
                        'tools_scope' => ['allowed_tools' => []],
                    ],
                ],
            ],
        );
    }

    private function createResolver(SubagentRunMetadataReader $childMetadataReader): SessionAwareModelResolver
    {
        $sessionMetaStore = new HatfieldSessionStore(
            appConfig: new AppConfig(
                tui: new TuiConfig(theme: 'default'),
                logging: new LoggingConfig(),
                cwd: $this->tempDir.'/project',
            ),
            entityManager: $this->entityManager,
        );

        $homeWriter = new SettingsOverrideWriter(
            new SettingsPathResolver($this->tempDir, $this->homeDir),
            PropertyAccess::createPropertyAccessor(),
            new Filesystem(),
        );

        $appConfig = $this->makeAppConfig();
        $selectionService = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $sessionMetaStore),
            $homeWriter,
            $sessionMetaStore,
        );

        $catalog = $appConfig->catalog ?? new HatfieldModelCatalog(new AiConfig(defaultModel: '', defaultReasoning: 'medium', providers: []));

        return new SessionAwareModelResolver($selectionService, $catalog, $sessionMetaStore, $childMetadataReader);
    }

    private function makeAppConfig(): AppConfig
    {
        $raw = [
            'ai' => [
                'default_model' => self::DEFAULT_CODEX_MODEL,
                'default_reasoning' => 'medium',
                'providers' => [
                    'deepseek' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'https://api.deepseek.com',
                        'completions_path' => '/chat/completions',
                        'models' => [
                            'deepseek-v4-flash' => [
                                'id' => 'deepseek-v4-flash',
                                'name' => 'DeepSeek V4 Flash',
                                'context_window' => 1000000,
                                'max_tokens' => 384000,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                        ],
                    ],
                    'openai-codex' => [
                        'type' => 'codex',
                        'enabled' => true,
                        'base_url' => 'https://chatgpt.com/backend-api',
                        'completions_path' => '/codex/responses',
                        'models' => [
                            'gpt-5.6-sol' => [
                                'id' => 'gpt-5.6-sol',
                                'name' => 'GPT-5.6 Sol',
                                'context_window' => 272000,
                                'max_tokens' => 128000,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                            'gpt-5.6-luna' => [
                                'id' => 'gpt-5.6-luna',
                                'name' => 'GPT-5.6 Luna',
                                'context_window' => 272000,
                                'max_tokens' => 128000,
                                'input' => ['text', 'image'],
                                'tool_calling' => true,
                                'reasoning' => true,
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $ai = AiConfig::optionalFromArray($raw);

        return new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: getcwd() ?: '/',
        );
    }

    private function createAdapter(SessionAwareModelResolver $resolver, FakeSymfonyModelClient $client): LlmPlatformAdapter
    {
        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addSubscriber(new ModelResolverRoutingSubscriber($resolver));

        $platform = new Platform(
            providers: [new Provider(
                name: 'fake',
                modelClients: [$client],
                resultConverters: [new FakeStreamResultConverter(static fn (): iterable => [new TextDelta('ok')])],
                modelCatalog: new \Symfony\AI\Platform\ModelCatalog\FallbackModelCatalog(),
                eventDispatcher: $eventDispatcher,
            )],
            eventDispatcher: $eventDispatcher,
        );

        return new LlmPlatformAdapter(
            runStore: new InMemoryRunStore(),
            messageConverter: new AgentMessageConverter(),
            toolDescriptionProcessor: new DynamicToolDescriptionProcessor(),
            platform: $platform,
            transformContextHooks: [],
            convertToLlmHooks: [],
            streamObserver: null,
            costCalculator: null,
            modelResolver: $resolver,
            logger: new TestLogger(),
            denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
        );
    }
}
