<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\ModelResolver;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SettingsOverrideWriter;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Entity\HatfieldSession;
use Ineersa\CodingAgent\Runtime\Contract\StartRunRequest;
use Ineersa\CodingAgent\Session\HatfieldSessionStore;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;

/**
 * @covers \Ineersa\CodingAgent\CLI\AgentCommand
 *
 * Covers the --model/--reasoning option contract for TUI startup:
 *  - prompt-bearing launches still ride StartRunRequest,
 *  - prompt-less --resume with explicit options updates session selection
 *    before any request,
 *  - omitted resume options preserve the existing session selection.
 */
final class AgentCommandModelOptionTest extends IsolatedKernelTestCase
{
    private string $tempDir = '';
    private string $homeDir = '';
    private HatfieldSessionStore $sessionStore;
    private ModelSelectionService $modelSelectionService;
    private string $sessionId = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = TestDirectoryIsolation::createProjectTempDir('hatfield-agent-model-option', 0o750);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir.'/.hatfield', 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);
        file_put_contents($this->homeDir.'/.hatfield/settings.yaml', "tui:\n    theme: default\n");

        $entityManager = static::getContainer()->get('doctrine.orm.default_entity_manager');
        $appConfig = $this->buildAppConfig($this->tempDir.'/project');
        $this->sessionStore = new HatfieldSessionStore(
            appConfig: $appConfig,
            entityManager: $entityManager,
            dispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
        $pathResolver = new SettingsPathResolver($this->tempDir.'/project', $this->homeDir);
        $homeWriter = new SettingsOverrideWriter(
            $pathResolver,
            PropertyAccess::createPropertyAccessor(),
            new Filesystem(),
        );
        $this->modelSelectionService = new ModelSelectionService(
            $appConfig,
            new ModelResolver($appConfig, $this->sessionStore, new NullLogger()),
            $homeWriter,
            $this->sessionStore,
        );

        $entity = new HatfieldSession();
        $entity->cwd = $this->tempDir.'/project';
        $entity->model = 'llama_cpp/flash';
        $entity->modelProvider = 'llama_cpp';
        $entity->modelName = 'flash';
        $entity->reasoning = 'medium';
        $entityManager->persist($entity);
        $entityManager->flush();
        $this->sessionId = (string) $entity->id;
    }

    protected function tearDown(): void
    {
        if ('' !== $this->tempDir) {
            TestDirectoryIsolation::removeDirectory($this->tempDir);
        }
        parent::tearDown();
    }

    #[Test]
    public function promptWithModelBuildsFullRequest(): void
    {
        $request = $this->buildInitialRequest('hello', 'llama_cpp_test/test', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertSame('llama_cpp_test/test', $request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function promptWithoutOptionsBuildsPlainRequest(): void
    {
        $request = $this->buildInitialRequest('hello', '', '');

        $this->assertNotNull($request);
        $this->assertSame('hello', $request->prompt);
        $this->assertNull($request->model);
        $this->assertNull($request->reasoning);
    }

    #[Test]
    public function noPromptBuildsNoRequest(): void
    {
        $this->assertNull($this->buildInitialRequest('', 'llama_cpp_test/test', 'high'));
        $this->assertNull($this->buildInitialRequest('', '', ''));
    }

    #[Test]
    public function resumeWithoutPromptAppliesExplicitModelAndReasoningBeforeRequest(): void
    {
        $this->applyResumeSelectionOverrides($this->sessionId, 'llama_cpp_test/test', 'high');

        $session = $this->sessionStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('llama_cpp_test/test', $session->model);
        $this->assertSame('llama_cpp_test', $session->modelProvider);
        $this->assertSame('test', $session->modelName);
        $this->assertSame('high', $session->reasoning);
        $this->assertNull($this->buildInitialRequest('', 'llama_cpp_test/test', 'high'));
    }

    #[Test]
    public function resumeWithoutPromptPreservesSessionWhenOptionsOmitted(): void
    {
        $this->applyResumeSelectionOverrides($this->sessionId, '', '');

        $session = $this->sessionStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('llama_cpp/flash', $session->model);
        $this->assertSame('medium', $session->reasoning);
        $this->assertNull($this->buildInitialRequest('', '', ''));
    }

    #[Test]
    public function resumeWithOnlyModelLeavesExistingReasoning(): void
    {
        $this->applyResumeSelectionOverrides($this->sessionId, 'llama_cpp_test/test', '');

        $session = $this->sessionStore->findSession($this->sessionId);
        $this->assertNotNull($session);
        $this->assertSame('llama_cpp_test/test', $session->model);
        $this->assertSame('medium', $session->reasoning);
    }

    private function buildInitialRequest(string $prompt, string $model, string $reasoning): ?StartRunRequest
    {
        $method = new \ReflectionMethod(\Ineersa\CodingAgent\CLI\AgentCommand::class, 'buildInitialRequest');

        return $method->invoke(null, $prompt, $model, $reasoning);
    }

    private function applyResumeSelectionOverrides(string $sessionId, string $model, string $reasoning): void
    {
        $method = new \ReflectionMethod(\Ineersa\CodingAgent\CLI\AgentCommand::class, 'applyResumeSelectionOverrides');
        $command = (new \ReflectionClass(\Ineersa\CodingAgent\CLI\AgentCommand::class))
            ->newInstanceWithoutConstructor();

        $selectionProperty = new \ReflectionProperty(\Ineersa\CodingAgent\CLI\AgentCommand::class, 'modelSelectionService');
        $selectionProperty->setValue($command, $this->modelSelectionService);

        $method->invoke($command, $sessionId, $model, $reasoning);
    }

    private function buildAppConfig(string $cwd): AppConfig
    {
        $raw = [
            'tui' => ['theme' => 'default'],
            'ai' => [
                'default_model' => 'llama_cpp/flash',
                'default_reasoning' => 'medium',
                'providers' => [
                    'llama_cpp' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://127.0.0.1:8052/v1',
                        'models' => [
                            'flash' => [
                                'name' => 'flash',
                                'context_window' => 32768,
                                'max_tokens' => 8192,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => true,
                                'reasoning_levels' => ['off', 'low', 'medium', 'high'],
                            ],
                        ],
                    ],
                    'llama_cpp_test' => [
                        'type' => 'generic',
                        'enabled' => true,
                        'base_url' => 'http://127.0.0.1:9052/v1',
                        'models' => [
                            'test' => [
                                'name' => 'test',
                                'context_window' => 32768,
                                'max_tokens' => 32768,
                                'input' => ['text'],
                                'tool_calling' => true,
                                'reasoning' => true,
                                'reasoning_levels' => ['off', 'low', 'medium', 'high'],
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
            ai: $ai,
            raw: $raw,
            catalog: null !== $ai ? new HatfieldModelCatalog($ai) : null,
            cwd: $cwd,
        );
    }
}
