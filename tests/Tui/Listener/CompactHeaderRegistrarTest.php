<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Listener;

use Ineersa\AgentCore\Tests\Support\TestLogger;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Mcp\Config\McpConfigLoader;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateArgumentParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateSubstitutor;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tests\Support\Mcp\TestMcpConfigLoaderFactory;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\CompactHeader\CompactHeaderSnapshotProvider;
use Ineersa\Tui\Listener\CompactHeaderRegistrar;
use Ineersa\Tui\Runtime\TuiSessionState;
use Ineersa\Tui\Tests\Support\TuiRuntimeContextBuilderTrait;
use Ineersa\Tui\Tests\Support\VirtualTuiHarness;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Tui\Event\TickEvent;
use Symfony\Component\Validator\Validation;

final class CompactHeaderRegistrarTest extends TestCase
{
    use TuiRuntimeContextBuilderTrait;

    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('compact-header-registrar');
        putenv('HOME='.$this->tmpDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function snapshotFailureDoesNotPropagateFromTick(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'throws-reg');
        $state = new TuiSessionState('throws-reg');

        $provider = new CompactHeaderSnapshotProvider(
            $this->promptCatalog(),
            $this->skillDiscovery(),
            $this->agentDiscovery(),
            $this->throwingMcpStore(),
            $this->mcpConfigLoader(),
        );

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new CompactHeaderRegistrar($provider, new NullLogger()))->register($context);
        $context->ticks->dispatch(new TickEvent());

        // Snapshot must stay unset (hidden) when the provider throws.
        $harness->render();
        $this->assertStringNotContainsString('reg-skill', $harness->plainScreenText());
    }

    #[Test]
    public function preSeedsPinnedWidgetOnRegisterRegardlessOfResume(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'compact-reg');
        $state = new TuiSessionState('compact-reg');
        $state->resuming = true;

        $provider = new CompactHeaderSnapshotProvider(
            $this->promptCatalog(),
            $this->skillDiscovery(),
            $this->agentDiscovery(),
            $this->mcpStore(),
            $this->mcpConfigLoader(),
        );

        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();

        (new CompactHeaderRegistrar($provider))->register($context);

        $harness->render();
        $this->assertStringContainsString('reg-skill', $harness->plainScreenText());
    }

    #[Test]
    public function testCompactHeaderHiddenWhileLiveViewActive(): void
    {
        $harness = new VirtualTuiHarness(sessionId: 'compact-live');
        $state = new TuiSessionState('compact-live');
        $provider = new CompactHeaderSnapshotProvider(
            $this->promptCatalog(),
            $this->skillDiscovery(),
            $this->agentDiscovery(),
            $this->mcpStore(),
            $this->mcpConfigLoader(),
        );
        $context = $this->buildTuiContext()
            ->withTui($harness->tui())
            ->withState($state)
            ->withScreen($harness->screen())
            ->build();
        (new CompactHeaderRegistrar($provider, new NullLogger()))->register($context);
        $context->ticks->dispatch(new TickEvent());
        $harness->render();
        $this->assertStringContainsString('reg-skill', $harness->plainScreenText());

        $state->subagentLiveView->active = true;
        $context->ticks->dispatch(new TickEvent());
        $harness->render();
        $this->assertStringNotContainsString('reg-skill', $harness->plainScreenText());
    }

    private function promptCatalog(): PromptTemplateService
    {
        $loader = new PromptTemplateLoader(
            promptsConfig: new PromptsConfig(),
            runtimeConfig: new PromptTemplatesRuntimeConfig(),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            cwd: $this->tmpDir,
            frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
            logger: new TestLogger(),
        );

        return new PromptTemplateService(
            $loader,
            new PromptTemplateArgumentParser(),
            new PromptTemplateSubstitutor(),
        );
    }

    private function skillDiscovery(): SkillDiscovery
    {
        mkdir($this->tmpDir.'/.agents/skills/reg-skill', 0777, true);
        file_put_contents(
            $this->tmpDir.'/.agents/skills/reg-skill/SKILL.md',
            "---\nname: reg-skill\ndescription: x\n---\n",
        );

        return new SkillDiscovery(
            config: new SkillsConfig(),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            appConfig: new AppConfig(tui: new TuiConfig(theme: 'default', themePaths: []), logging: new LoggingConfig(), cwd: $this->tmpDir),
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($this->tmpDir),
            filesystem: new Filesystem(),
            logger: new NullLogger(),
        );
    }

    private function agentDiscovery(): AgentDefinitionDiscovery
    {
        return new AgentDefinitionDiscovery(
            agentsConfig: new AgentsConfig(enabled: false),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            parser: $this->agentParser(),
            cwd: $this->tmpDir,
        );
    }

    private function mcpStore(): McpToolCatalogStoreInterface
    {
        $store = $this->createStub(McpToolCatalogStoreInterface::class);
        $store->method('read')->willReturn(null);

        return $store;
    }

    private function mcpConfigLoader(): McpConfigLoader
    {
        return TestMcpConfigLoaderFactory::create(
            new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            $this->tmpDir,
        );
    }

    private function throwingMcpStore(): McpToolCatalogStoreInterface
    {
        $store = $this->createStub(McpToolCatalogStoreInterface::class);
        $store->method('read')->willThrowException(new \RuntimeException('MCP catalog run ID must not be empty.'));

        return $store;
    }

    private function agentParser(): AgentDefinitionParser
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
        $validator = Validation::createValidatorBuilder()->enableAttributeMapping()->getValidator();

        return new AgentDefinitionParser(
            frontmatterParser: new AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $serializer,
            validator: $validator,
        );
    }
}
