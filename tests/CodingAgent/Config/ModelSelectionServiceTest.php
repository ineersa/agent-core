<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Config;

use Ineersa\CodingAgent\Config\Ai\AiModelReference;
use Ineersa\CodingAgent\Config\AppConfigLoader;
use Ineersa\CodingAgent\Config\AppConfigResolver;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\HomeSettingsWriter;
use Ineersa\CodingAgent\Config\ModelSelectionService;
use Ineersa\CodingAgent\Config\SessionMetadataStore;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

class ModelSelectionServiceTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private ModelSelectionService $service;
    private SessionMetadataStore $sessionMetaStore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hatfield-model-selection-test-'.uniqid('', true);
        $this->homeDir = $this->tempDir.'/home';
        mkdir($this->homeDir, 0777, true);
        mkdir($this->tempDir.'/project/.hatfield/sessions', 0777, true);

        $resources = new AppResourceLocator($this->tempDir);
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);
        $loader = new AppConfigLoader($pathResolver);
        // HomeSettingsWriter needs SettingsPathResolver for internal home path resolution
        $homeWriter = new HomeSettingsWriter($pathResolver);

        // We need to provide a defaults file so the loader works
        $defaultsPath = $this->tempDir.'/config/hatfield.defaults.yaml';
        mkdir(\dirname($defaultsPath), 0777, true);
        file_put_contents($defaultsPath, "tui:\n    theme: cyberpunk\n");

        $configResolver = new AppConfigResolver($loader, $resources);
        $this->sessionMetaStore = new SessionMetadataStore();
        $this->sessionMetaStore->setSessionsBasePath($this->projectCwd().'/.hatfield/sessions');

        $this->service = new ModelSelectionService(
            $configResolver,
            $homeWriter,
            $this->sessionMetaStore,
        );
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tempDir);
        parent::tearDown();
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                rmdir($file->getPathname());
            } else {
                chmod($file->getPathname(), 0644);
                unlink($file->getPathname());
            }
        }

        rmdir($dir);
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Write an AI config section to the project's .hatfield/settings.yaml.
     */
    private function writeProjectAiConfig(array $aiData): void
    {
        $path = $this->tempDir.'/project/.hatfield/settings.yaml';
        $data = ['ai' => $aiData, 'tui' => ['theme' => 'cyberpunk']];
        file_put_contents($path, Yaml::dump($data, 4, 2));
        // Clear resolver cache
        // We can't easily clear the cache so we'll recreate the resolver each time in setUp
    }

    /**
     * Write home settings (the defaults layer copy).
     */
    private function writeHomeSettings(array $data): void
    {
        $path = $this->homeDir.'/.hatfield/settings.yaml';
        file_put_contents($path, Yaml::dump($data, 4, 2));
    }

    /**
     * Write session metadata YAML.
     */
    private function writeSessionMetadata(string $sessionId, array $meta): void
    {
        $dir = $this->tempDir.'/project/.hatfield/sessions/'.$sessionId;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($dir.'/metadata.yaml', Yaml::dump($meta, 4, 2));
    }

    /**
     * Read session metadata YAML.
     */
    private function readSessionMetadata(string $sessionId): array
    {
        $path = $this->tempDir.'/project/.hatfield/sessions/'.$sessionId.'/metadata.yaml';

        if (!is_readable($path)) {
            return [];
        }

        $data = Yaml::parseFile($path);

        return \is_array($data) ? $data : [];
    }

    private function projectCwd(): string
    {
        return $this->tempDir.'/project';
    }

    private function homeSettingsPath(): string
    {
        return $this->homeDir.'/.hatfield/settings.yaml';
    }

    // ──────────────────────────────────────────────
    //  Model resolution priority chain
    // ──────────────────────────────────────────────

    public function testExplicitModelWinsOverAllOtherPriorities(): void
    {
        // Set up: different models at every priority tier
        // Session has llama_cpp/flash, default is deepseek-v4-pro
        $this->writeProjectAiConfig([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
                'llama_cpp' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'http://llama', 'models' => [
                    'flash' => ['name' => 'flash'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['model' => 'llama_cpp/flash']);

        // Explicit should win
        $result = $this->service->resolveInitialModel('deepseek/deepseek-v4-pro', 'abc123', $this->projectCwd());

        self::assertNotNull($result);
        self::assertSame('deepseek', $result->providerId);
        self::assertSame('deepseek-v4-pro', $result->modelName);
    }

    public function testSessionMetadataWinsOverDefaultAndFirstAvailable(): void
    {
        $this->writeProjectAiConfig([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'providers' => [
                'llama_cpp' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'http://llama', 'models' => [
                    'flash' => ['name' => 'flash'],
                ]],
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['model' => 'llama_cpp/flash']);

        $result = $this->service->resolveInitialModel(null, 'abc123', $this->projectCwd());

        self::assertNotNull($result);
        self::assertSame('llama_cpp', $result->providerId);
        self::assertSame('flash', $result->modelName);
    }

    public function testDefaultModelWinsOverFirstAvailable(): void
    {
        $this->writeProjectAiConfig([
            'default_model' => 'llama_cpp/flash',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
                'llama_cpp' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'http://llama', 'models' => [
                    'flash' => ['name' => 'flash'],
                ]],
            ],
        ]);

        $result = $this->service->resolveInitialModel(null, '', $this->projectCwd());

        self::assertNotNull($result);
        self::assertSame('llama_cpp/flash', $result->toString());
    }

    public function testFirstAvailableModelUsedWhenNoDefault(): void
    {
        $this->writeProjectAiConfig([
            'providers' => [
                'zai' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://z.ai', 'models' => [
                    'glm-5.1' => ['name' => 'GLM 5.1'],
                ]],
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $result = $this->service->resolveInitialModel(null, '', $this->projectCwd());

        // First provider in config order: zai → glm-5.1
        self::assertNotNull($result);
        self::assertSame('zai/glm-5.1', $result->toString());
    }

    public function testReturnsNullWhenNoModelsConfigured(): void
    {
        // No AI config at all
        $result = $this->service->resolveInitialModel(null, '', $this->projectCwd());

        self::assertNull($result);
    }

    public function testExplicitModelIgnoredWhenUnavailable(): void
    {
        $this->writeProjectAiConfig([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        // Explicit model is invalid/unknown — should fall through to default
        $result = $this->service->resolveInitialModel('unknown/model', '', $this->projectCwd());

        self::assertNotNull($result);
        self::assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    public function testNewSessionDoesNotReadMetadata(): void
    {
        // Session metadata exists but empty sessionId means new session
        $this->writeProjectAiConfig([
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        // New session (empty sessionId) → skip metadata, go to default/first-available
        $result = $this->service->resolveInitialModel(null, '', $this->projectCwd());

        self::assertNotNull($result);
        self::assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    // ──────────────────────────────────────────────
    //  Reasoning resolution priority chain
    // ──────────────────────────────────────────────

    public function testExplicitReasoningWinsOverMetadataAndDefault(): void
    {
        $this->writeProjectAiConfig([
            'default_reasoning' => 'low',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['reasoning' => 'off']);

        $result = $this->service->resolveInitialReasoning('xhigh', 'abc123', $this->projectCwd());

        self::assertSame('xhigh', $result);
    }

    public function testSessionReasoningWinsOverDefault(): void
    {
        $this->writeProjectAiConfig([
            'default_reasoning' => 'low',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['reasoning' => 'high']);

        $result = $this->service->resolveInitialReasoning(null, 'abc123', $this->projectCwd());

        self::assertSame('high', $result);
    }

    public function testDefaultReasoningUsedWhenNoExplicitOrSession(): void
    {
        $this->writeProjectAiConfig([
            'default_reasoning' => 'xhigh',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $result = $this->service->resolveInitialReasoning(null, '', $this->projectCwd());

        self::assertSame('xhigh', $result);
    }

    public function testReasoningFallsBackToMedium(): void
    {
        // No default_reasoning configured
        $this->writeProjectAiConfig([
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $result = $this->service->resolveInitialReasoning(null, '', $this->projectCwd());

        self::assertSame('medium', $result);
    }

    // ──────────────────────────────────────────────
    //  Model change and persistence
    // ──────────────────────────────────────────────

    public function testChangeModelPersistsToHomeAndSession(): void
    {
        $this->writeProjectAiConfig([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
                'llama_cpp' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'http://llama', 'models' => [
                    'flash' => ['name' => 'flash'],
                ]],
            ],
        ]);

        // Create initial session metadata
        $this->writeSessionMetadata('abc123', [
            'session_id' => 'abc123',
            'model' => 'deepseek/deepseek-v4-pro',
        ]);

        $ref = AiModelReference::parse('llama_cpp/flash');
        $this->service->changeModel($ref, 'abc123', $this->projectCwd());

        // Home settings should be updated
        $homeData = Yaml::parseFile($this->homeSettingsPath());
        self::assertSame('llama_cpp/flash', $homeData['ai']['default_model'] ?? null);

        // Session metadata should be updated
        $sessionMeta = $this->readSessionMetadata('abc123');
        self::assertSame('llama_cpp/flash', $sessionMeta['model']);
        self::assertSame('llama_cpp', $sessionMeta['model_provider']);
        self::assertSame('flash', $sessionMeta['model_name']);

        // Other metadata should be preserved
        self::assertSame('abc123', $sessionMeta['session_id']);
    }

    public function testChangeModelThrowsOnUnavailableModel(): void
    {
        $this->writeProjectAiConfig([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['session_id' => 'abc123']);

        $ref = AiModelReference::parse('unknown/model');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not available');

        $this->service->changeModel($ref, 'abc123', $this->projectCwd());
    }

    // ──────────────────────────────────────────────
    //  Reasoning change and persistence
    // ──────────────────────────────────────────────

    public function testChangeReasoningPersistsToHomeAndSession(): void
    {
        $this->writeProjectAiConfig([
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', [
            'session_id' => 'abc123',
            'reasoning' => 'medium',
        ]);

        $this->service->changeReasoning('xhigh', 'abc123', $this->projectCwd());

        // Home settings should be updated
        $homeData = Yaml::parseFile($this->homeSettingsPath());
        self::assertSame('xhigh', $homeData['ai']['default_reasoning'] ?? null);

        // Session metadata should be updated
        $sessionMeta = $this->readSessionMetadata('abc123');
        self::assertSame('xhigh', $sessionMeta['reasoning']);
    }

    public function testChangeReasoningThrowsOnInvalidLevel(): void
    {
        $this->writeProjectAiConfig([
            'default_reasoning' => 'medium',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['session_id' => 'abc123']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid reasoning level');

        $this->service->changeReasoning('super-genius', 'abc123', $this->projectCwd());
    }

    // ──────────────────────────────────────────────
    //  getAvailableModels
    // ──────────────────────────────────────────────

    public function testGetAvailableModelsReturnsAllEnabledProviderModels(): void
    {
        $this->writeProjectAiConfig([
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                    'deepseek-v4-flash' => ['name' => 'V4 Flash'],
                ]],
                'llama_cpp' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'http://llama', 'models' => [
                    'flash' => ['name' => 'flash'],
                ]],
                'disabled' => ['type' => 'generic', 'enabled' => false, 'base_url' => 'https://disabled.example.com', 'models' => [
                    'hidden' => ['name' => 'Hidden'],
                ]],
            ],
        ]);

        $models = $this->service->getAvailableModels($this->projectCwd());

        self::assertCount(3, $models);

        $refs = array_map(static fn (AiModelReference $r): string => $r->toString(), $models);
        self::assertContains('deepseek/deepseek-v4-pro', $refs);
        self::assertContains('deepseek/deepseek-v4-flash', $refs);
        self::assertContains('llama_cpp/flash', $refs);
        self::assertNotContains('disabled/hidden', $refs);
    }

    // ──────────────────────────────────────────────
    //  Edge cases
    // ──────────────────────────────────────────────

    public function testSessionMetadataWithCorruptModelIgnored(): void
    {
        // Session has invalid model format — should fall through to default
        $this->writeProjectAiConfig([
            'default_model' => 'deepseek/deepseek-v4-pro',
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', ['model' => 'not-a-valid-ref']);

        $result = $this->service->resolveInitialModel(null, 'abc123', $this->projectCwd());

        self::assertNotNull($result);
        self::assertSame('deepseek/deepseek-v4-pro', $result->toString());
    }

    public function testChangeReasoningPreservesSessionMetadata(): void
    {
        $this->writeProjectAiConfig([
            'providers' => [
                'deepseek' => ['type' => 'generic', 'enabled' => true, 'base_url' => 'https://api.deepseek.com', 'models' => [
                    'deepseek-v4-pro' => ['name' => 'V4 Pro'],
                ]],
            ],
        ]);

        $this->writeSessionMetadata('abc123', [
            'session_id' => 'abc123',
            'run_id' => 'abc123',
            'cwd' => '/some/path',
            'model' => 'deepseek/deepseek-v4-pro',
        ]);

        $this->service->changeReasoning('high', 'abc123', $this->projectCwd());

        $meta = $this->readSessionMetadata('abc123');

        // New key added
        self::assertSame('high', $meta['reasoning']);

        // Existing keys preserved
        self::assertSame('abc123', $meta['session_id']);
        self::assertSame('abc123', $meta['run_id']);
        self::assertSame('/some/path', $meta['cwd']);
        self::assertSame('deepseek/deepseek-v4-pro', $meta['model']);
    }
}
