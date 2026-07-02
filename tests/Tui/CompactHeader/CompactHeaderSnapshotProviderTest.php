<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\CompactHeader;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\PromptsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogEntryDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpServerCatalogStatusEnum;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogDTO;
use Ineersa\CodingAgent\Mcp\Catalog\McpToolCatalogStoreInterface;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateFrontmatterParser;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateLoader;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplateService;
use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCatalogInterface;
use Ineersa\CodingAgent\Runtime\Contract\PromptTemplateCommand;
use Ineersa\CodingAgent\Skills\SkillDiscovery;
use Ineersa\CodingAgent\Skills\SkillsConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Tui\CompactHeader\CompactHeaderSnapshotProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

final class CompactHeaderSnapshotProviderTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = TestDirectoryIsolation::createProjectTempDir('compact-header-provider');
        putenv('HOME='.$this->tmpDir);
        $_ENV['HOME'] = $this->tmpDir;
        $_SERVER['HOME'] = $this->tmpDir;
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tmpDir);
    }

    #[Test]
    public function aggregatesPromptsSkillsAgentsAndMcp(): void
    {
        $promptCatalog = new class implements PromptTemplateCatalogInterface {
            public function allPromptTemplateCommands(): array
            {
                return [
                    new PromptTemplateCommand('review', 'Review'),
                    new PromptTemplateCommand('plan', 'Plan'),
                ];
            }
        };

        mkdir($this->tmpDir.'/.agents/skills/castor', 0777, true);
        file_put_contents(
            $this->tmpDir.'/.agents/skills/castor/SKILL.md',
            "---\nname: castor\ndescription: Castor\n---\n",
        );

        $skillDiscovery = new SkillDiscovery(
            config: new SkillsConfig(),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            appConfig: $this->appConfig(),
            extractor: new MarkdownFrontmatterExtractor(),
            logger: new NullLogger(),
        );

        $agentsDir = $this->tmpDir.'/.hatfield/agents';
        mkdir($agentsDir, 0777, true);
        file_put_contents($agentsDir.'/scout.md', "---\nname: scout\ndescription: Scout\n---\n");
        file_put_contents($agentsDir.'/worker.md', "---\nname: worker\ndescription: Worker\ndisabled: true\n---\n");

        $agentDiscovery = new AgentDefinitionDiscovery(
            agentsConfig: new AgentsConfig(enabled: true),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            parser: $this->agentParser(),
            cwd: $this->tmpDir,
        );

        $mcpStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $mcpStore->method('read')->willReturn(new McpToolCatalogDTO(
            servers: [
                'browser' => new McpServerCatalogEntryDTO('browser', 'stdio', McpServerCatalogStatusEnum::CONNECTED, tools: []),
                'bad' => new McpServerCatalogEntryDTO('bad', 'stdio', McpServerCatalogStatusEnum::FAILED, errorMessage: 'x'),
            ],
        ));

        $snapshot = (new CompactHeaderSnapshotProvider($promptCatalog, $skillDiscovery, $agentDiscovery, $mcpStore))->build('sess-1');

        self::assertSame(['plan', 'review'], $snapshot->prompts);
        self::assertSame(['castor'], $snapshot->skills);
        self::assertSame(1, $snapshot->agentCount);
        self::assertSame(['scout'], $snapshot->agentNames);
        self::assertCount(2, $snapshot->mcpServers);
        $byName = [];
        foreach ($snapshot->mcpServers as $entry) {
            $byName[$entry->name] = $entry;
        }
        self::assertSame('✓', $byName['browser']->icon);
        self::assertSame('✗', $byName['bad']->icon);
    }

    #[Test]
    public function nullMcpCatalogOmitsServers(): void
    {
        $promptCatalog = new class implements PromptTemplateCatalogInterface {
            public function allPromptTemplateCommands(): array
            {
                return [];
            }
        };

        $skillDiscovery = new SkillDiscovery(
            config: new SkillsConfig(noSkills: true),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            appConfig: $this->appConfig(),
            extractor: new MarkdownFrontmatterExtractor(),
            logger: new NullLogger(),
        );

        $agentDiscovery = new AgentDefinitionDiscovery(
            agentsConfig: new AgentsConfig(enabled: false),
            pathResolver: new SettingsPathResolver($this->tmpDir, $this->tmpDir),
            parser: $this->agentParser(),
            cwd: $this->tmpDir,
        );

        $mcpStore = $this->createStub(McpToolCatalogStoreInterface::class);
        $mcpStore->method('read')->willReturn(null);

        $snapshot = (new CompactHeaderSnapshotProvider($promptCatalog, $skillDiscovery, $agentDiscovery, $mcpStore))->build('sess-2');

        self::assertSame([], $snapshot->mcpServers);
        self::assertTrue($snapshot->isEmpty());
    }

    private function appConfig(): AppConfig
    {
        return new AppConfig(
            tui: new TuiConfig(theme: 'default', themePaths: []),
            logging: new LoggingConfig(),
            cwd: $this->tmpDir,
        );
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
