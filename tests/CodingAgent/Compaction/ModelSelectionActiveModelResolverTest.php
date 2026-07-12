<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Compaction;

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
        $resolver = new ModelSelectionActiveModelResolver($service);

        $this->assertSame('llama_cpp/flash', $resolver->resolveActiveModel(''));
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
}
