<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

use Ineersa\CodingAgent\Agent\Execution\Subagent\ChildRun\ChildRunDefinitionModelLookupInterface;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Compaction\ModelSelectionActiveModelResolver;
use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\ModelSettingsPersister;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SessionsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class ModelSelectionActiveModelResolverTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('active-model-resolver');
        $this->homeDir = TestDirectoryIsolation::createOsTempDir('active-model-resolver-home');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
        TestDirectoryIsolation::removeDirectory($this->homeDir);
    }

    public function testResolveActiveModelUsesCatalogDefaultWhenSessionHasNoModel(): void
    {
        $aiData = [
            'default_model' => 'llama_cpp/flash',
            'providers' => [
                'llama_cpp' => [
                    'enabled' => true,
                    'models' => ['flash' => ['enabled' => true]],
                ],
            ],
        ];

        $service = $this->buildService($aiData);
        $resolver = new ModelSelectionActiveModelResolver(
            $service,
            $this->createStubLookup(null),
            $this->createMetadataReader(null),
        );

        $this->assertSame('llama_cpp/flash', $resolver->resolveActiveModel(''));
    }

    public function testResolveActiveModelUsesChildDefinitionModelForNonNumericRunId(): void
    {
        $aiData = [
            'default_model' => 'openai-codex/gpt-5.6-sol',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
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
                    'models' => [
                        'gpt-5.6-sol' => [
                            'id' => 'gpt-5.6-sol',
                            'name' => 'GPT-5.6 Sol',
                            'context_window' => 372000,
                            'max_tokens' => 128000,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                        ],
                    ],
                ],
            ],
        ];

        $service = $this->buildService($aiData);
        $resolver = new ModelSelectionActiveModelResolver(
            $service,
            $this->createStubLookup('deepseek/deepseek-v4-flash'),
            $this->createMetadataReader(null),
        );

        $this->assertSame(
            'deepseek/deepseek-v4-flash',
            $resolver->resolveActiveModel('3d451a76-e371-5ece-b9ca-8769167d85e4'),
        );
    }

    public function testResolveActiveModelUsesAgentChildRunStartedMetadataWhenDeferredRowMissing(): void
    {
        $aiData = [
            'default_model' => 'openai-codex/gpt-5.6-sol',
            'providers' => [
                'deepseek' => [
                    'type' => 'generic',
                    'enabled' => true,
                    'base_url' => 'https://api.deepseek.com',
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
                    'models' => [
                        'gpt-5.6-sol' => [
                            'id' => 'gpt-5.6-sol',
                            'name' => 'GPT-5.6 Sol',
                            'context_window' => 372000,
                            'max_tokens' => 128000,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                        ],
                    ],
                ],
            ],
        ];

        $service = $this->buildService($aiData);
        $resolver = new ModelSelectionActiveModelResolver(
            $service,
            $this->createStubLookup(null),
            $this->createMetadataReader('deepseek/deepseek-v4-flash'),
        );

        $this->assertSame(
            'deepseek/deepseek-v4-flash',
            $resolver->resolveActiveModel('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'),
        );
    }

    public function testResolveActiveModelFailsClosedForUnknownChildUuid(): void
    {
        $aiData = [
            'default_model' => 'openai-codex/gpt-5.6-sol',
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'base_url' => 'https://chatgpt.com/backend-api',
                    'models' => [
                        'gpt-5.6-sol' => [
                            'id' => 'gpt-5.6-sol',
                            'name' => 'GPT-5.6 Sol',
                            'context_window' => 372000,
                            'max_tokens' => 128000,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                        ],
                    ],
                ],
            ],
        ];

        $service = $this->buildService($aiData);
        $resolver = new ModelSelectionActiveModelResolver(
            $service,
            $this->createStubLookup(null),
            $this->createMetadataReader(null),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no durable child definition model or agent_child run_started model exists');
        $resolver->resolveActiveModel('deadbeef-0000-4000-8000-000000000000');
    }

    public function testResolveActiveModelFailsClosedForArbitraryNonNumericLabel(): void
    {
        $aiData = [
            'default_model' => 'openai-codex/gpt-5.6-sol',
            'providers' => [
                'openai-codex' => [
                    'type' => 'codex',
                    'enabled' => true,
                    'base_url' => 'https://chatgpt.com/backend-api',
                    'models' => [
                        'gpt-5.6-sol' => [
                            'id' => 'gpt-5.6-sol',
                            'name' => 'GPT-5.6 Sol',
                            'context_window' => 372000,
                            'max_tokens' => 128000,
                            'input' => ['text'],
                            'tool_calling' => true,
                            'reasoning' => true,
                        ],
                    ],
                ],
            ],
        ];

        $service = $this->buildService($aiData);
        $resolver = new ModelSelectionActiveModelResolver(
            $service,
            $this->createStubLookup(null),
            $this->createMetadataReader(null),
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('expected a numeric hatfield_session id or a deferred-subagent child UUID');
        $resolver->resolveActiveModel('e2e-controller-label');
    }

    private function buildService(array $aiData): ModelSelectionService
    {
        $raw = ['tui' => ['theme' => 'default'], 'ai' => $aiData];
        $ai = AiConfig::optionalFromArray($raw);
        $appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'default']),
            logging: new LoggingConfig(),
            sessions: new SessionsConfig(),
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $this->tempDir,
        );

        $sessionMetaRc = new \ReflectionClass(SessionMetadataStore::class);
        $sessionMetaStore = $sessionMetaRc->newInstanceWithoutConstructor();
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $homeWriter = new HomeSettingsWriter($pathResolver);
        $modelResolver = new ModelResolver($appConfig, $sessionMetaStore);
        $persisterRc = new \ReflectionClass(ModelSettingsPersister::class);
        $persister = $persisterRc->newInstanceWithoutConstructor();

        return new ModelSelectionService($appConfig, $modelResolver, $persister);
    }

    private function createStubLookup(?string $definitionModel): ChildRunDefinitionModelLookupInterface
    {
        return new class($definitionModel) implements ChildRunDefinitionModelLookupInterface {
            public function __construct(private readonly ?string $definitionModel)
            {
            }

            public function findDefinitionModelByChildRunId(string $childRunId): ?string
            {
                unset($childRunId);

                return $this->definitionModel;
            }
        };
    }

    private function createMetadataReader(?string $model): SubagentRunMetadataReader
    {
        $eventStore = new class($model) implements \Ineersa\AgentCore\Contract\EventStoreInterface {
            public function __construct(private readonly ?string $model)
            {
            }

            public function append(\Ineersa\AgentCore\Domain\Event\RunEvent $event): \Ineersa\AgentCore\Domain\Event\RunEvent
            {
                return $event;
            }

            public function appendMany(array $events): array
            {
                return $events;
            }

            public function allFor(string $runId): array
            {
                unset($runId);
                if (null === $this->model) {
                    return [];
                }

                return [
                    new \Ineersa\AgentCore\Domain\Event\RunEvent(
                        runId: 'child',
                        seq: 1,
                        turnNo: 0,
                        type: \Ineersa\AgentCore\Domain\Event\RunEventTypeEnum::RunStarted->value,
                        payload: [
                            'payload' => [
                                'metadata' => [
                                    'session' => ['kind' => 'agent_child'],
                                    'model' => $this->model,
                                ],
                            ],
                        ],
                    ),
                ];
            }
        };

        return new SubagentRunMetadataReader($eventStore);
    }
}
