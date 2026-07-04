<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Infrastructure\SymfonyAi;

use Ineersa\CodingAgent\Config\Ai\AiConfig;
use Ineersa\CodingAgent\Config\Ai\AiHttpConfig;
use Ineersa\CodingAgent\Config\Ai\AiModelDefinition;
use Ineersa\CodingAgent\Config\Ai\AiProviderConfig;
use Ineersa\CodingAgent\Config\Ai\HatfieldModelCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Infrastructure\SymfonyAi\SymfonyAiProviderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\Generic\CompletionsModel;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class SymfonyAiProviderFactoryTest extends TestCase
{
    // ── Raw stream capture writer (env-gated) ──────────────────────────

    private ?string $savedCaptureEnv = null;
    private ?string $savedCapturePathEnv = null;

    protected function setUp(): void
    {
        parent::setUp();
        // Save env vars so we can restore them in tearDown
        $this->savedCaptureEnv = false !== getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE') ? getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE') : null;
        $this->savedCapturePathEnv = false !== getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH') ? getenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH') : null;
    }

    protected function tearDown(): void
    {
        // Restore env vars
        if (null !== $this->savedCaptureEnv) {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE='.$this->savedCaptureEnv);
        } else {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE');
        }
        if (null !== $this->savedCapturePathEnv) {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH='.$this->savedCapturePathEnv);
        } else {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH');
        }
        unset($_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'], $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH']);
        parent::tearDown();
    }

    public function testGenericTypeBuildsProvider(): void
    {
        $appConfig = $this->appConfig();
        $eventDispatcher = $this->createStub(EventDispatcherInterface::class);
        $factory = new SymfonyAiProviderFactory($appConfig, $eventDispatcher);

        $providers = $factory->createProviders();

        $this->assertArrayHasKey('deepseek', $providers);
        $this->assertNotNull($providers['deepseek']);

        // Verify the generic path still produces CompletionsModel
        $catalog = $providers['deepseek']->getModelCatalog();
        $model = $catalog->getModel('deepseek/deepseek-v4-pro');
        $this->assertInstanceOf(CompletionsModel::class, $model);
    }

    public function testCodexTypeThrowsWithoutAuthStorage(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $factory->createProviders();
    }

    public function testCodexTypeThrowsRegardlessOfYamlCredentials(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: true,
            baseUrl: 'https://chatgpt.com/backend-api',
            apiKey: 'some-access-token',
            accountId: 'chat-123456',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('requires stored OAuth credentials');

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $factory->createProviders();
    }

    public function testDisabledCodexProviderIsSkipped(): void
    {
        $providerConfig = new AiProviderConfig(
            id: 'openai-codex',
            type: 'codex',
            enabled: false,
            baseUrl: 'https://chatgpt.com/backend-api',
        );

        $factory = $this->createFactory(['openai-codex' => $providerConfig]);
        $providers = $factory->createProviders();

        $this->assertArrayNotHasKey('openai-codex', $providers);
    }

    public function testCustomHttpConfigIsAcceptedByFactory(): void
    {
        $deepseek = new AiProviderConfig(
            id: 'deepseek',
            type: 'generic',
            enabled: true,
            baseUrl: 'https://api.deepseek.com',
            apiKey: 'dummy-key',
            models: [
                'deepseek-v4-pro' => new AiModelDefinition(
                    id: 'deepseek-v4-pro',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $http = new AiHttpConfig(timeout: 15, maxDuration: 60);
        $aiConfig = new AiConfig(
            defaultModel: 'deepseek/deepseek-v4-pro',
            http: $http,
            providers: ['deepseek' => $deepseek],
        );

        $appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        $factory = new SymfonyAiProviderFactory(
            $appConfig,
            $this->createStub(EventDispatcherInterface::class),
        );

        $providers = $factory->createProviders();
        $this->assertArrayHasKey('deepseek', $providers);
    }

    public function testCaptureDisabledByDefaultDoesNotCreateFile(): void
    {
        // Ensure env is not set
        putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE');
        putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH');
        unset($_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'], $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH']);

        $factory = $this->createFactory([
            'test-provider' => new AiProviderConfig(
                id: 'test-provider',
                type: 'generic',
                enabled: true,
                baseUrl: 'https://api.example.com',
                apiKey: null,
            ),
        ]);

        $capture = $this->invokeBuildCaptureListener($factory, 'test-provider');

        $this->assertNull($capture, 'buildCaptureListener should return null when env is not set');
    }

    public function testCaptureEnabledReturnsClosureThatWritesValidJsonl(): void
    {
        $tmpDir = TestDirectoryIsolation::createProjectTempDir('capture-test', 0o750);
        $capturePath = $tmpDir.'/capture.jsonl';

        try {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE=1');
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH='.$capturePath);
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'] = '1';
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] = $capturePath;

            $factory = $this->createFactory([
                'test-provider' => new AiProviderConfig(
                    id: 'test-provider',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://api.example.com',
                    apiKey: null,
                ),
            ]);

            $capture = $this->invokeBuildCaptureListener($factory, 'test-provider');

            $this->assertNotNull($capture, 'buildCaptureListener should return a closure when env is set');
            $this->assertFileExists($capturePath, 'Capture file should be created');

            // Check file permissions are restrictive (0600)
            $perms = fileperms($capturePath) & 0o777;
            $this->assertSame(0o600, $perms, 'Capture file should have 0600 permissions');

            // Check directory permissions are restrictive (0700)
            $dirPerms = fileperms($tmpDir) & 0o777;
            $this->assertSame(0o750, $dirPerms, 'Temp dir should have 0750 permissions');
            $captureDirPerms = fileperms(\dirname($capturePath)) & 0o777;
            // The immediate parent is $tmpDir which we set to 0750
            $this->assertSame(0o750, $captureDirPerms);

            // Write sample events through the closure
            $capture('capture_start', -1, ['provider_id' => 'test-provider']);
            $capture('raw_chunk', 0, ['data' => ['choices' => [['delta' => ['content' => 'Hello']]]]]);
            $capture('converted_delta', 0, ['type' => 'TextDelta', 'text' => 'Hello']);
            $capture('capture_end', -1, ['stop_reason' => 'stop']);

            // Read back and validate JSONL
            $lines = file($capturePath, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            $this->assertCount(4, $lines, 'Should have 4 JSONL lines');

            $records = array_map(static fn (string $line) => json_decode($line, true, flags: \JSON_THROW_ON_ERROR), $lines);

            // capture_start
            $this->assertSame('capture_start', $records[0]['event']);
            $this->assertSame('test-provider', $records[0]['provider_id']);
            $this->assertArrayHasKey('timestamp', $records[0]);
            $this->assertSame(-1, $records[0]['ordinal']);

            // raw_chunk
            $this->assertSame('raw_chunk', $records[1]['event']);
            $this->assertSame('test-provider', $records[1]['provider_id']);
            $this->assertSame(0, $records[1]['ordinal']);
            $this->assertArrayHasKey('data', $records[1]);

            // converted_delta
            $this->assertSame('converted_delta', $records[2]['event']);
            $this->assertSame('test-provider', $records[2]['provider_id']);
            $this->assertSame(0, $records[2]['ordinal']);
            $this->assertSame('TextDelta', $records[2]['type']);

            // capture_end
            $this->assertSame('capture_end', $records[3]['event']);
            $this->assertSame('test-provider', $records[3]['provider_id']);
            $this->assertSame('stop', $records[3]['stop_reason']);
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    public function testCaptureDisabledWithExplicitZeroDoesNotCreateFile(): void
    {
        $tmpDir = TestDirectoryIsolation::createProjectTempDir('capture-disabled', 0o750);
        $capturePath = $tmpDir.'/capture.jsonl';

        try {
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE=0');
            putenv('HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH='.$capturePath);
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE'] = '0';
            $_ENV['HATFIELD_LLM_RAW_STREAM_CAPTURE_PATH'] = $capturePath;

            $factory = $this->createFactory([
                'test-provider' => new AiProviderConfig(
                    id: 'test-provider',
                    type: 'generic',
                    enabled: true,
                    baseUrl: 'https://api.example.com',
                    apiKey: null,
                ),
            ]);

            $capture = $this->invokeBuildCaptureListener($factory, 'test-provider');

            $this->assertNull($capture, 'buildCaptureListener should return null when HATFIELD_LLM_RAW_STREAM_CAPTURE=0');
            $this->assertFileDoesNotExist($capturePath, 'Capture file should not be created when disabled');
        } finally {
            TestDirectoryIsolation::removeDirectory($tmpDir);
        }
    }

    /**
     * @param array<string, AiProviderConfig> $providers
     */
    private function createFactory(array $providers): SymfonyAiProviderFactory
    {
        $aiConfig = new AiConfig(
            defaultModel: 'openai-codex/gpt-5.5',
            providers: $providers,
        );

        $appConfig = new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );

        return new SymfonyAiProviderFactory(
            $appConfig,
            $this->createStub(EventDispatcherInterface::class),
        );
    }

    /**
     * Invoke private SymfonyAiProviderFactory::buildCaptureListener() via reflection.
     *
     * @return (\Closure(string, int, array<string, mixed>): void)|null
     */
    private function invokeBuildCaptureListener(SymfonyAiProviderFactory $factory, string $providerId): mixed
    {
        $method = new \ReflectionMethod(SymfonyAiProviderFactory::class, 'buildCaptureListener');

        return $method->invoke($factory, $providerId);
    }

    private function appConfig(): AppConfig
    {
        $deepseekConfig = new AiProviderConfig(
            id: 'deepseek',
            type: 'generic',
            enabled: true,
            baseUrl: 'https://api.deepseek.com',
            apiKey: 'dummy-key',
            models: [
                'deepseek-v4-pro' => new AiModelDefinition(
                    id: 'deepseek-v4-pro',
                    toolCalling: true,
                    reasoning: true,
                ),
            ],
        );

        $aiConfig = new AiConfig(
            defaultModel: 'deepseek/deepseek-v4-pro',
            providers: ['deepseek' => $deepseekConfig],
        );

        return new AppConfig(
            tui: TuiConfig::fromArray(['theme' => 'cyberpunk']),
            logging: new LoggingConfig(),
            catalog: new HatfieldModelCatalog($aiConfig),
        );
    }
}
