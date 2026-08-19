<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\LoadedResources;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Config\Ai\AiCatalog;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\AppResourceLocator;
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
use Ineersa\Tui\Theme\ThemeRegistry;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Contract tests: skill, prompt, and agent collisions surface winner vs loser paths in summary DTO.
 */
final class LoadedResourcesSummaryBuilderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createOsTempDir('loaded-resources-builder');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function testSkillCollisionWinnerAndIgnoredPathsPropagate(): void
    {
        $high = $this->tmpDir.'/high/myskill';
        $low = $this->tmpDir.'/low/myskill';
        mkdir($high, 0777, true);
        mkdir($low, 0777, true);
        file_put_contents($high.'/SKILL.md', "---\nname: myskill\ndescription: high\n---\n");
        file_put_contents($low.'/SKILL.md', "---\nname: myskill\ndescription: low\n---\n");

        $skillsConfig = new SkillsConfig(noSkills: true, skillsPaths: [$this->tmpDir.'/high', $this->tmpDir.'/low']);
        $skillDiscovery = new SkillDiscovery(
            config: $skillsConfig,
            pathResolver: new SettingsPathResolver($this->tmpDir),
            appConfig: $this->appConfig($this->tmpDir),
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($this->tmpDir),
            filesystem: new Filesystem(),
            logger: new NullLogger(),
        );

        $builder = new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $this->appConfig($this->tmpDir),
            ),
            skillDiscovery: $skillDiscovery,
            promptTemplateLoader: $this->emptyPromptLoader(),
            agentDefinitionDiscovery: $this->disabledAgentDiscovery(),
            themeLoadedResourcesProvider: $this->emptyThemeRegistry(),
            extensionManager: $this->emptyExtensionManager(),
        );

        $summary = $builder->build();
        $skills = $this->sectionByKey($summary, 'skills');

        $this->assertCount(1, $skills->conflicts);
        $this->assertSame('myskill', $skills->conflicts[0]->name);
        $this->assertStringContainsString('/high/myskill', $skills->conflicts[0]->winnerPath);
    }

    #[Test]
    public function testPromptCollisionWinnerAndLoserPathsPropagate(): void
    {
        $homeDir = $this->tmpDir.'/home';
        $cwd = $this->tmpDir.'/project';
        mkdir($homeDir, 0777, true);
        mkdir($cwd, 0777, true);
        mkdir($homeDir.'/.hatfield/prompts', 0777, true);
        mkdir($cwd.'/.hatfield/prompts', 0777, true);
        $globalFile = $homeDir.'/.hatfield/prompts/review.md';
        $projectFile = $cwd.'/.hatfield/prompts/review.md';
        file_put_contents($globalFile, "Global review.\n");
        file_put_contents($projectFile, "Project review.\n");

        $pathResolver = new SettingsPathResolver('/app', $homeDir);
        $promptLoader = new PromptTemplateLoader(
            promptsConfig: new PromptsConfig(),
            runtimeConfig: new PromptTemplatesRuntimeConfig(),
            pathResolver: $pathResolver,
            cwd: $cwd,
            frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
            logger: new NullLogger(),
        );

        $builder = new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $this->appConfig($cwd),
            ),
            skillDiscovery: $this->emptySkillDiscovery($cwd),
            promptTemplateLoader: $promptLoader,
            agentDefinitionDiscovery: $this->disabledAgentDiscovery(),
            themeLoadedResourcesProvider: $this->emptyThemeRegistryForCwd($cwd),
            extensionManager: $this->emptyExtensionManagerForCwd($cwd),
        );

        $summary = $builder->build();
        $prompts = $this->sectionByKey($summary, 'prompts');

        $this->assertCount(1, $prompts->conflicts);
        $this->assertSame('review', $prompts->conflicts[0]->name);
        $this->assertSame($globalFile, $prompts->conflicts[0]->winnerPath);
        $this->assertSame($projectFile, $prompts->conflicts[0]->loserPath);
    }

    #[Test]
    public function testAiCatalogSkewAddsWarningConflictSection(): void
    {
        $home = $this->tmpDir.'/home';
        mkdir($home.'/.hatfield', 0777, true);
        $catalogPath = $this->tmpDir.'/ai-catalog.yaml';
        file_put_contents($catalogPath, "version: 2\nproviders: {}\n");
        file_put_contents($home.'/.hatfield/ai-catalog.yaml', "version: 1\nproviders: {}\n");

        $builder = new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $this->appConfig($this->tmpDir),
            ),
            skillDiscovery: $this->emptySkillDiscovery($this->tmpDir),
            promptTemplateLoader: $this->emptyPromptLoader(),
            agentDefinitionDiscovery: $this->disabledAgentDiscovery(),
            themeLoadedResourcesProvider: $this->emptyThemeRegistry(),
            extensionManager: $this->emptyExtensionManager(),
            aiCatalog: new AiCatalog($catalogPath, $home),
        );

        $summary = $builder->build();
        $section = $this->sectionByKey($summary, 'ai-catalog');

        $this->assertSame('AI Catalog', $section->label);
        $this->assertSame([], $section->items);
        $this->assertCount(1, $section->conflicts);
        $this->assertSame('AI Catalog', $section->conflicts[0]->name);
        $this->assertSame('', $section->conflicts[0]->winnerPath);
        $this->assertSame('update available — run bin/console providers:update', $section->conflicts[0]->message);
        $this->assertContains($section, $summary->nonEmptySections());
    }

    #[Test]
    public function testAiCatalogAbsentWhenNoSkewOrNullCatalog(): void
    {
        $withoutCatalog = new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $this->appConfig($this->tmpDir),
            ),
            skillDiscovery: $this->emptySkillDiscovery($this->tmpDir),
            promptTemplateLoader: $this->emptyPromptLoader(),
            agentDefinitionDiscovery: $this->disabledAgentDiscovery(),
            themeLoadedResourcesProvider: $this->emptyThemeRegistry(),
            extensionManager: $this->emptyExtensionManager(),
        );

        foreach ($withoutCatalog->build()->sections as $section) {
            $this->assertNotSame('ai-catalog', $section->key);
        }

        $home = $this->tmpDir.'/home-equal';
        mkdir($home.'/.hatfield', 0777, true);
        $catalogPath = $this->tmpDir.'/ai-catalog-equal.yaml';
        file_put_contents($catalogPath, "version: 1\nproviders: {}\n");
        file_put_contents($home.'/.hatfield/ai-catalog.yaml', "version: 1\nproviders: {}\n");

        $equal = new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $this->appConfig($this->tmpDir),
            ),
            skillDiscovery: $this->emptySkillDiscovery($this->tmpDir),
            promptTemplateLoader: $this->emptyPromptLoader(),
            agentDefinitionDiscovery: $this->disabledAgentDiscovery(),
            themeLoadedResourcesProvider: $this->emptyThemeRegistry(),
            extensionManager: $this->emptyExtensionManager(),
            aiCatalog: new AiCatalog($catalogPath, $home),
        );

        foreach ($equal->build()->sections as $section) {
            $this->assertNotSame('ai-catalog', $section->key);
        }
    }

    #[Test]
    public function testAgentCollisionWinnerAndLoserPathsPropagate(): void
    {
        $homeDir = $this->tmpDir.'/home';
        $cwd = $this->tmpDir.'/project';
        mkdir($homeDir, 0777, true);
        mkdir($cwd, 0777, true);
        mkdir($homeDir.'/.hatfield/agents', 0777, true);
        mkdir($cwd.'/.agents', 0777, true);
        // Scope-before-specificity: user Hatfield wins over project generic .agents.
        $winner = $homeDir.'/.hatfield/agents/collide.md';
        $loser = $cwd.'/.agents/collide.md';
        file_put_contents($winner, "---\nname: collide\ndescription: User-hatfield version\ntools: [read]\n---\n");
        file_put_contents($loser, "---\nname: collide\ndescription: Project-agents version\ntools: [read]\n---\n");

        $pathResolver = new SettingsPathResolver($this->tmpDir, $homeDir);
        $agentDiscovery = new AgentDefinitionDiscovery(
            agentsConfig: new \Ineersa\CodingAgent\Config\AgentsConfig(),
            pathResolver: $pathResolver,
            parser: $this->agentDefinitionParser(),
            cwd: $cwd,
        );

        $builder = new LoadedResourcesSummaryBuilder(
            agentsContextDiscovery: new AgentsContextDiscovery(
                pathResolver: new SettingsPathResolver($this->tmpDir),
                appConfig: $this->appConfig($cwd),
            ),
            skillDiscovery: $this->emptySkillDiscovery($cwd),
            promptTemplateLoader: $this->emptyPromptLoaderForCwd($cwd),
            agentDefinitionDiscovery: $agentDiscovery,
            themeLoadedResourcesProvider: $this->emptyThemeRegistryForCwd($cwd),
            extensionManager: $this->emptyExtensionManagerForCwd($cwd),
        );

        $summary = $builder->build();
        $agents = $this->sectionByKey($summary, 'agents');

        $this->assertCount(1, $agents->conflicts);
        $this->assertSame('collide', $agents->conflicts[0]->name);
        $this->assertSame($winner, $agents->conflicts[0]->winnerPath);
        $this->assertSame($loser, $agents->conflicts[0]->loserPath);
    }

    private function sectionByKey(\Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO $summary, string $key): \Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO
    {
        foreach ($summary->sections as $section) {
            if ($key === $section->key) {
                return $section;
            }
        }

        $this->fail('Missing section: '.$key);
    }

    private function appConfig(string $cwd): AppConfig
    {
        return new AppConfig(
            cwd: $cwd,
            logging: new LoggingConfig(),
            tui: new TuiConfig(theme: 'default', themePaths: []),
        );
    }

    private function emptyPromptLoader(): PromptTemplateLoader
    {
        return new PromptTemplateLoader(
            promptsConfig: new PromptsConfig(),
            runtimeConfig: (static function (): PromptTemplatesRuntimeConfig {
                $c = new PromptTemplatesRuntimeConfig();
                $c->noPromptTemplates = true;

                return $c;
            })(),
            pathResolver: new SettingsPathResolver($this->tmpDir),
            cwd: $this->tmpDir,
            frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
            logger: new NullLogger(),
        );
    }

    private function disabledAgentDiscovery(): AgentDefinitionDiscovery
    {
        return new AgentDefinitionDiscovery(
            agentsConfig: new \Ineersa\CodingAgent\Config\AgentsConfig(enabled: false),
            pathResolver: new SettingsPathResolver($this->tmpDir),
            parser: new \Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser(new \Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser(new MarkdownFrontmatterExtractor()), $this->createStub(\Symfony\Component\Serializer\Normalizer\DenormalizerInterface::class), $this->createStub(\Symfony\Component\Validator\Validator\ValidatorInterface::class)),
            cwd: $this->tmpDir,
        );
    }

    private function emptyThemeRegistry(): ThemeRegistry
    {
        $appConfig = $this->appConfig($this->tmpDir);
        $resources = new AppResourceLocator(ProjectDir::get());

        return new ThemeRegistry($appConfig, $resources, new NullLogger());
    }

    private function emptySkillDiscovery(string $cwd): SkillDiscovery
    {
        $skillsConfig = new SkillsConfig(noSkills: true);

        return new SkillDiscovery(
            config: $skillsConfig,
            pathResolver: new SettingsPathResolver($this->tmpDir),
            appConfig: $this->appConfig($cwd),
            extractor: new MarkdownFrontmatterExtractor(),
            resources: new AppResourceLocator($this->tmpDir),
            filesystem: new Filesystem(),
            logger: new NullLogger(),
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

    private function emptyPromptLoaderForCwd(string $cwd): PromptTemplateLoader
    {
        return new PromptTemplateLoader(
            promptsConfig: new PromptsConfig(),
            runtimeConfig: (static function (): PromptTemplatesRuntimeConfig {
                $c = new PromptTemplatesRuntimeConfig();
                $c->noPromptTemplates = true;

                return $c;
            })(),
            pathResolver: new SettingsPathResolver($this->tmpDir),
            cwd: $cwd,
            frontmatterParser: new PromptTemplateFrontmatterParser(new MarkdownFrontmatterExtractor()),
            logger: new NullLogger(),
        );
    }

    private function emptyThemeRegistryForCwd(string $cwd): ThemeRegistry
    {
        $appConfig = $this->appConfig($cwd);
        $resources = new AppResourceLocator(ProjectDir::get());

        return new ThemeRegistry($appConfig, $resources, new NullLogger());
    }

    private function emptyExtensionManagerForCwd(string $cwd): ExtensionManager
    {
        $config = new AppConfig(
            cwd: $cwd,
            logging: new LoggingConfig(),
            tui: new TuiConfig(theme: 'default', themePaths: []),
            extensions: new \Ineersa\CodingAgent\Config\ExtensionsConfig(enabled: []),
        );

        return new ExtensionManager(
            config: $config,
            extensionApi: $this->createStub(ExtensionApiInterface::class),
            logger: new NullLogger(),
            eventDispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
    }

    private function emptyExtensionManager(): ExtensionManager
    {
        $config = $this->appConfig($this->tmpDir);
        $config = new AppConfig(
            cwd: $this->tmpDir,
            logging: new LoggingConfig(),
            tui: new TuiConfig(theme: 'default', themePaths: []),
            extensions: new \Ineersa\CodingAgent\Config\ExtensionsConfig(enabled: []),
        );

        return new ExtensionManager(
            config: $config,
            extensionApi: $this->createStub(ExtensionApiInterface::class),
            logger: new NullLogger(),
            eventDispatcher: new \Symfony\Component\EventDispatcher\EventDispatcher(),
        );
    }
}
