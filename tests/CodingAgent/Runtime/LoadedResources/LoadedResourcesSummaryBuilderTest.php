<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\LoadedResources;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
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

/**
 * Contract test: skill name collisions surface winner vs ignored paths in summary DTO.
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
            themeRegistry: $this->emptyThemeRegistry(),
            extensionManager: $this->emptyExtensionManager(),
        );

        $summary = $builder->build();
        $skills = $this->sectionByKey($summary, 'skills');

        self::assertCount(1, $skills->conflicts);
        self::assertSame('myskill', $skills->conflicts[0]->name);
        self::assertStringContainsString('/high/myskill', $skills->conflicts[0]->winnerPath);
        self::assertStringContainsString('/low/myskill', $skills->conflicts[0]->loserPath);
    }

    private function sectionByKey(\Ineersa\CodingAgent\Runtime\Contract\LoadedResourcesSummaryDTO $summary, string $key): \Ineersa\CodingAgent\Runtime\Contract\LoadedResourceSectionDTO
    {
        foreach ($summary->sections as $section) {
            if ($key === $section->key) {
                return $section;
            }
        }

        self::fail('Missing section: '.$key);
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
            runtimeConfig: (function (): PromptTemplatesRuntimeConfig {
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
        $resources = new \Ineersa\CodingAgent\Config\AppResourceLocator(ProjectDir::get());

        return new ThemeRegistry($appConfig, $resources, new NullLogger());
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
        );
    }
}
