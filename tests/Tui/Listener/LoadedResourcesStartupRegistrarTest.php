<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Config\AgentsConfig;
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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Validator\Validation;

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
    public function preSeedsSummaryOnRegisterForFreshSession(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'defer-summary');
        $state = new TuiSessionState('defer-summary');
        $state->resuming = false;
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new LoadedResourcesStartupRegistrar($this->createMinimalBuilder()))->register($context);

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

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function provideCtrlRSequences(): iterable
    {
        yield 'legacy' => ["\x12"];
        yield 'kitty' => ["\x1b[114;5u"];
    }

    #[Test]
    #[DataProvider('provideCtrlRSequences')]
    public function ctrlRTogglesLoadedResourcesExpansion(string $sequence): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'toggle-resources');
        $state = new TuiSessionState('toggle-resources');
        $state->resuming = false;
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new LoadedResourcesStartupRegistrar($this->createMinimalBuilder()))->register($context);
        $this->assertTrue($harness->screen()->hasLoadedResourcesBlock());

        $before = $harness->plainScreenText();
        $harness->startInputLoop();
        $harness->sendInput($sequence);
        $after = $harness->plainScreenText();

        $this->assertNotSame($before, $after);
        $harness->stopInputLoop();
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
                resources: new AppResourceLocator($this->tmpDir),
                filesystem: new Filesystem(),
                denormalizer: AttributeSerializerValidatorTestFactory::denormalizer(),
                validator: AttributeSerializerValidatorTestFactory::create()[1],
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
                agentsConfig: new AgentsConfig(enabled: false),
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
                eventDispatcher: new EventDispatcher(),
                pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir.'/home'),
            ),
        );
    }

    private function agentDefinitionParser(): AgentDefinitionParser
    {
        $reflectionExtractor = new ReflectionExtractor();
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $objectNormalizer = new ObjectNormalizer(
            classMetadataFactory: $classMetadataFactory,
            nameConverter: null,
            propertyAccessor: PropertyAccess::createPropertyAccessor(),
            propertyTypeExtractor: $reflectionExtractor,
        );
        $serializer = new Serializer(normalizers: [$objectNormalizer], encoders: []);
        $validator = Validation::createValidatorBuilder()
            ->enableAttributeMapping()
            ->getValidator();

        return new AgentDefinitionParser(
            frontmatterParser: new AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $serializer,
            validator: $validator,
        );
    }
}
