<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Definition;

use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionDiscovery;
use Ineersa\CodingAgent\Agent\Definition\AgentDefinitionParser;
use Ineersa\CodingAgent\Agent\Definition\AgentFrontmatterParser;
use Ineersa\CodingAgent\Config\AgentsConfig;
use Ineersa\CodingAgent\Config\SettingsPathResolver;
use Ineersa\CodingAgent\Markdown\MarkdownFrontmatterExtractor;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\Validation;

/**
 * Tests for AgentDefinitionDiscovery covering discovery from user,
 * project, and configured paths, precedence, diagnostics, and edge cases.
 *
 * Test thesis: Discovery protects the stable contract that Hatfield can
 * locate, validate, override, diagnose, and list agent definition files
 * deterministically before any runtime launch work exists. There are no
 * bundled/built-in agent definitions.
 */
final class AgentDefinitionDiscoveryTest extends TestCase
{
    private string $tempDir;
    private string $homeDir;
    private string $cwd;
    private AgentDefinitionParser $parser;

    protected function setUp(): void
    {
        $this->tempDir = TestDirectoryIsolation::createProjectTempDir();
        $this->homeDir = $this->tempDir.'/home';
        $this->cwd = $this->tempDir.'/project';

        mkdir($this->homeDir, 0755, true);
        mkdir($this->cwd, 0755, true);

        // Set up parser
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

        $this->parser = new AgentDefinitionParser(
            frontmatterParser: new AgentFrontmatterParser(new MarkdownFrontmatterExtractor()),
            denormalizer: $serializer,
            validator: $validator,
        );
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->tempDir);
    }

    public function testDiscoversUserAgentsWithCommaSeparatedToolsAndMissingTools(): void
    {
        $this->createValidDefinition(
            $this->homeDir.'/.agents/reviewer.md',
            'reviewer',
            ['tools' => 'read, grep, bash'],
        );

        $scoutDir = $this->homeDir.'/.agents';
        if (!is_dir($scoutDir)) {
            mkdir($scoutDir, 0755, true);
        }
        file_put_contents(
            $scoutDir.'/scout.md',
            '---
name: scout
description: Scout agent
inheritProjectContext: true
---
Body
',
        );

        $catalog = $this->createDiscovery()->discover();

        $this->assertCount(2, $catalog->enabled());
        $byName = [];
        foreach ($catalog->enabled() as $definition) {
            $byName[$definition->name] = $definition;
        }
        $this->assertSame(['read', 'grep', 'bash'], $byName['reviewer']->tools);
        $this->assertNull($byName['scout']->tools);
    }

    public function testDiscoversAgentFromUserHatfieldAgents(): void
    {
        $this->createValidDefinition(
            $this->homeDir.'/.hatfield/agents/l1.md',
            'l1',
            ['description' => 'First-layer agent'],
        );

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $definition = $catalog->get('l1');
        $this->assertNotNull($definition);
        $this->assertSame('First-layer agent', $definition->description);
        $this->assertStringContainsString('Test body.', $definition->instructions);
    }

    public function testUserHatfieldOverridesUserAgents(): void
    {
        $this->createValidDefinition(
            $this->homeDir.'/.hatfield/agents/dupe.md',
            'dupe',
            ['description' => 'User-hatfield version'],
        );
        $this->createValidDefinition(
            $this->homeDir.'/.agents/dupe.md',
            'dupe',
            ['description' => 'User-agents version'],
        );

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $definition = $catalog->get('dupe');
        $this->assertNotNull($definition);
        $this->assertSame('User-hatfield version', $definition->description);
    }

    public function testProjectHatfieldOverridesUser(): void
    {
        $this->createValidDefinition(
            $this->homeDir.'/.hatfield/agents/shared.md',
            'shared',
            ['description' => 'User version'],
        );
        $this->createValidDefinition(
            $this->cwd.'/.hatfield/agents/shared.md',
            'shared',
            ['description' => 'Project version'],
        );

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $definition = $catalog->get('shared');
        $this->assertNotNull($definition);
        $this->assertSame('Project version', $definition->description);
    }

    public function testProjectHatfieldOverridesProjectAgents(): void
    {
        $this->createValidDefinition(
            $this->cwd.'/.hatfield/agents/override.md',
            'override',
            ['description' => 'Project-hatfield version'],
        );
        $this->createValidDefinition(
            $this->cwd.'/.agents/override.md',
            'override',
            ['description' => 'Project-agents version'],
        );

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $definition = $catalog->get('override');
        $this->assertNotNull($definition);
        $this->assertSame('Project-hatfield version', $definition->description);
    }

    public function testConfiguredPathsHaveHighestPrecedence(): void
    {
        // Create in project .agents
        $this->createValidDefinition(
            $this->cwd.'/.agents/vip.md',
            'vip',
            ['description' => 'Project-agents version'],
        );

        // Create in configured path
        $configuredDir = $this->tempDir.'/configured-agents';
        $this->createValidDefinition(
            $configuredDir.'/vip.md',
            'vip',
            ['description' => 'Configured-path version'],
        );

        $config = new AgentsConfig(enabled: true, paths: [$configuredDir]);
        $discovery = $this->createDiscovery($config);
        $catalog = $discovery->discover();

        $definition = $catalog->get('vip');
        $this->assertNotNull($definition);
        $this->assertSame('Configured-path version', $definition->description);
    }

    public function testConfiguredMissingPathProducesDiagnostic(): void
    {
        $config = new AgentsConfig(enabled: true, paths: ['/nonexistent/path/agent.md']);
        $discovery = $this->createDiscovery($config);
        $catalog = $discovery->discover();

        $diagnostics = $catalog->diagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame('missing_path', $diagnostics[0]->type);
        $this->assertStringContainsString('/nonexistent/path/agent.md', $diagnostics[0]->message);
    }

    public function testConfiguredNonMdFileProducesInvalidPathDiagnostic(): void
    {
        // Create a non-.md file and point agents.paths at it.
        $configuredFile = $this->tempDir.'/not-a-def.txt';
        file_put_contents($configuredFile, 'plain text, not markdown');

        $config = new AgentsConfig(enabled: true, paths: [$configuredFile]);
        $discovery = $this->createDiscovery($config);
        $catalog = $discovery->discover();

        // Should have an invalid_path diagnostic, not silently skipped.
        $diagnostics = $catalog->diagnostics();
        $this->assertCount(1, $diagnostics);
        $this->assertSame('invalid_path', $diagnostics[0]->type);
        $this->assertSame($configuredFile, $diagnostics[0]->path);
        $this->assertStringContainsString('not a .md file', $diagnostics[0]->message);
    }

    public function testInvalidDefinitionProducesDiagnostic(): void
    {
        $dir = $this->cwd.'/.agents';
        mkdir($dir, 0755, true);
        // This file has an unknown field "invalid" which AgentDefinitionParser rejects
        file_put_contents($dir.'/bad.md', "---\ninvalid: true\n---\nbad body\n");

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $this->assertNull($catalog->get('bad'));

        $hasInvalidDef = false;
        foreach ($catalog->diagnostics() as $d) {
            if ('invalid_definition' === $d->type) {
                $hasInvalidDef = true;
                break;
            }
        }
        $this->assertTrue($hasInvalidDef, 'Expected an invalid_definition diagnostic');
    }

    public function testOverrideProducesCollisionDiagnostic(): void
    {
        $this->createValidDefinition(
            $this->homeDir.'/.hatfield/agents/collide.md',
            'collide',
            ['description' => 'Lower precedence'],
        );
        $this->createValidDefinition(
            $this->cwd.'/.agents/collide.md',
            'collide',
            ['description' => 'Higher precedence'],
        );

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        // The catalog should have the winning definition
        $definition = $catalog->get('collide');
        $this->assertNotNull($definition);
        $this->assertSame('Higher precedence', $definition->description);

        // And a collision diagnostic
        $hasCollision = false;
        foreach ($catalog->diagnostics() as $d) {
            if ('collision' === $d->type && 'collide' === $d->name) {
                $hasCollision = true;
                $this->assertNotEmpty($d->winnerPath);
                $this->assertNotEmpty($d->loserPath);
                break;
            }
        }
        $this->assertTrue($hasCollision, 'Expected a collision diagnostic for "collide"');
    }

    public function testAutoDiscoveryMissingDirsSilentlySkipped(): void
    {
        // None of the auto-discovery dirs exist.
        // Catalog should be empty; no missing_path diagnostics.
        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $this->assertCount(0, $catalog->all(), 'No auto dirs exist so catalog must be empty');
        $this->assertCount(0, $catalog->diagnostics(), 'Auto-discovery missing dirs should not emit diagnostics');
    }

    public function testDisabledDefinitionStillInAllAndDisabled(): void
    {
        $this->createValidDefinition(
            $this->cwd.'/.agents/disabled-agent.md',
            'disabled-agent',
            ['description' => 'Disabled by default', 'disabled' => true],
        );

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $this->assertNotNull($catalog->get('disabled-agent'));
        $allNames = array_map(static fn ($d) => $d->name, $catalog->all());
        $disabledNames = array_map(static fn ($d) => $d->name, $catalog->disabled());
        $enabledNames = array_map(static fn ($d) => $d->name, $catalog->enabled());

        $this->assertContains('disabled-agent', $allNames);
        $this->assertContains('disabled-agent', $disabledNames);
        $this->assertNotContains('disabled-agent', $enabledNames);
    }

    public function testDiscoveryIsCached(): void
    {
        $discovery = $this->createDiscovery();
        $catalog1 = $discovery->discover();
        $catalog2 = $discovery->discover();

        // Same instance reference after caching
        $this->assertSame($catalog1, $catalog2);
    }

    public function testDisabledDiscoveryReturnsEmptyCatalog(): void
    {
        // Put a valid definition in a configured path so there would be
        // things to discover if the enabled flag were ignored.
        $configuredDir = $this->tempDir.'/configured';
        $this->createValidDefinition($configuredDir.'/should-be-skipped.md', 'skipped', []);

        // enabled=false with a nonexistent path to prove diagnostics are suppressed
        $config = new AgentsConfig(enabled: false, paths: ['/nonexistent/path/agent.md']);
        $discovery = $this->createDiscovery($config);
        $catalog = $discovery->discover();

        $this->assertCount(0, $catalog->all(), 'Catalog must be empty when agents.enabled is false');
        $this->assertCount(0, $catalog->diagnostics(), 'No diagnostics expected when discovery is disabled');
    }

    public function testMultipleDefinitionsDiscovered(): void
    {
        $this->createValidDefinition($this->cwd.'/.agents/alpha.md', 'alpha', ['description' => 'Alpha agent']);
        $this->createValidDefinition($this->cwd.'/.agents/beta.md', 'beta', ['description' => 'Beta agent']);

        $discovery = $this->createDiscovery();
        $catalog = $discovery->discover();

        $all = $catalog->all();
        $this->assertCount(2, $all);
        $this->assertNotNull($catalog->get('alpha'));
        $this->assertNotNull($catalog->get('beta'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function validFrontmatter(array $data): string
    {
        $lines = [];
        foreach ($data as $key => $value) {
            if (\is_string($value)) {
                $lines[] = "{$key}: ".json_encode($value, \JSON_UNESCAPED_SLASHES);
            } elseif (\is_bool($value)) {
                $lines[] = "{$key}: ".($value ? 'true' : 'false');
            } elseif (\is_array($value)) {
                if (array_is_list($value)) {
                    if ([] === $value) {
                        $lines[] = "{$key}: []";
                    } else {
                        $lines[] = "{$key}:";
                        foreach ($value as $item) {
                            $lines[] = '  - '.json_encode($item, \JSON_UNESCAPED_SLASHES);
                        }
                    }
                }
            } elseif (\is_int($value)) {
                $lines[] = "{$key}: {$value}";
            } elseif (null === $value) {
                $lines[] = "{$key}: null";
            }
        }

        return "---\n".implode("\n", $lines)."\n---\nTest body.\n";
    }

    /**
     * Create a definition file with valid frontmatter at the given path.
     *
     * @param array<string, mixed> $overrides
     */
    private function createValidDefinition(string $path, string $name, array $overrides = []): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $data = array_merge([
            'name' => $name,
            'description' => ucfirst($name).' agent',
            'tools' => ['read'],
        ], $overrides);

        file_put_contents($path, self::validFrontmatter($data));
    }

    private function createDiscovery(?AgentsConfig $config = null): AgentDefinitionDiscovery
    {
        $pathResolver = new SettingsPathResolver($this->tempDir, $this->homeDir);

        return new AgentDefinitionDiscovery(
            agentsConfig: $config ?? new AgentsConfig(),
            pathResolver: $pathResolver,
            parser: $this->parser,
            cwd: $this->cwd,
        );
    }
}
