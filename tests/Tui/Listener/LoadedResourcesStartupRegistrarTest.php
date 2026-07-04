<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO;
use Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryProviderInterface;
use Ineersa\CodingAgent\Runtime\LoadedResources\LoadedResourcesSummaryBuilder;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\SystemPrompt\AgentsContextDiscovery;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\ExtensionApiInterface;
use Ineersa\Tui\Listener\LoadedResourcesStartupRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Tui\Event\TickEvent;

final class LoadedResourcesStartupRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('loaded-resources-registrar');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
        parent::tearDown();
    }

    #[Test]
    public function defersSummaryBuildUntilFirstTickOnFreshSession(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'defer-summary');
        $state = new TuiSessionState('defer-summary');
        $state->resuming = false;
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        $this->assertFalse($harness->screen()->hasLoadedResourcesBlock());

        (new LoadedResourcesStartupRegistrar($this->createMinimalBuilder()))->register($context);

        $this->assertFalse($harness->screen()->hasLoadedResourcesBlock());

        $context->ticks->dispatch(new TickEvent());

        $this->assertTrue($harness->screen()->hasLoadedResourcesBlock());
    }

    #[Test]
    public function doesNotBuildSummaryOnResume(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'defer-resume');
        $state = new TuiSessionState('defer-resume');
        $state->resuming = true;
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new LoadedResourcesStartupRegistrar($this->createMinimalBuilder()))->register($context);
        $context->ticks->dispatch(new TickEvent());

        $this->assertFalse($harness->screen()->hasLoadedResourcesBlock());
    }

    #[Test]
    public function buildIsNotInvokedBeforeFirstTick(): void
    {
        $calls = 0;
        $provider = new class($calls) implements LoadedResourcesSummaryProviderInterface {
            public function __construct(private int &$calls)
            {
            }

            public function build(): LoadedResourcesSummaryDTO
            {
                ++$this->calls;

                return new LoadedResourcesSummaryDTO([]);
            }
        };

        $harness = new VirtualTuiHarness(sessionId: 'no-sync-build');
        $state = new TuiSessionState('no-sync-build');
        $state->resuming = false;
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new LoadedResourcesStartupRegistrar($provider))->register($context);

        $this->assertSame(0, $calls, 'summary build must not run during register() / pre-loop startup');

        $context->ticks->dispatch(new TickEvent());

        $this->assertSame(1, $calls);
    }

    private function createMinimalBuilder(): LoadedResourcesSummaryBuilder
    {
        $appConfig = new AppConfig(
            cwd: $this->tmpDir,
            logging: new LoggingConfig(),
            tui: new TuiConfig(theme: 'default', themePaths: []),
        );

        $runtimeConfig = new PromptTemplatesRuntimeConfig();
        $runtimeConfig->noPromptTemplates = true;

        return new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $appConfig,
            ),
            skillDiscovery: new SkillDiscovery(
                config: new SkillsConfig(noSkills: true),
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $appConfig,
                extractor: new MarkdownFrontmatterExtractor(),
                logger: new NullLogger(),
            ),
            promptTemplateLoader: new PromptTemplateLoader(
                promptsConfig: new PromptsConfig(),
                runtimeConfig: $runtimeConfig,
                pathResolver: new SettingsPathResolver($this->tmpDir),
                cwd: $this->tmpDir,
                frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
                logger: new NullLogger(),
            ),
            agentDefinitionDiscovery: new AgentDefinitionDiscovery(
                agentsConfig: new \Ineersa\CodingAgent\Config\AgentsConfig(enabled: false),
                pathResolver: new SettingsPathResolver($this->tmpDir),
                parser: $this->agentDefinitionParser(),
                cwd: $this->tmpDir,
            ),
            themeLoadedResourcesProvider: new ThemeRegistry(
                $appConfig,
                new AppResourceLocator(ProjectDir::get()),
                new NullLogger(),
            ),
            extensionManager: new ExtensionManager(
                config: new AppConfig(
                    cwd: $this->tmpDir,
                    logging: new LoggingConfig(),
                    tui: new TuiConfig(theme: 'default', themePaths: []),
                    extensions: new ExtensionsConfig(enabled: []),
                ),
                extensionApi: $this->createStub(ExtensionApiInterface::class),
                logger: new NullLogger(),
            ),
        );
    }

    private function agentDefinitionParser(): \Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser
    {
        $reflectionExtractor = new \Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor();
        $classMetadataFactory = new \Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory(new \Symfony\Component\Serializer\Mapping\Loader\AttributeLoader());
        $objectNormalizer = new \Symfony\Component\Serializer\Normalizer\ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: null,
            propertyAccessor: \Symfony\Component\PropertyAccess\PropertyAccess::createPropertyAccessor(),
            propertyTypeExtractor: $reflectionExtractor,
        );
        $serializer = new \Symfony\Component\Serializer\Serializer(normalizers: [$objectNormalizer], encoders: []);
        $validator = \Symfony\Component\Validator\Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return new \Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser(
            frontmatterParser: new \Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $serializer,
            validator: $validator,
        );
    }
}
