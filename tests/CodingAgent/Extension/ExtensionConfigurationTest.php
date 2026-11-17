<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ExtensionConfigurationTest extends TestCase
{
    public function testOmManifestDropsTheRemovedSemanticImplementationsDependencies(): void
    {
        $manifest = json_decode(file_get_contents(ProjectDir::get().'/.hatfield/extensions/observational-memory/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
        // Model execution uses host contracts. These unused beta dependencies
        // silently kept stable-only Composer consumers on the old OM release.
        $this->assertArrayNotHasKey('symfony/ai-generic-platform', $manifest['require']);
        $this->assertArrayNotHasKey('symfony/ai-platform', $manifest['require']);
    }

    public function testExtensionOwnedDefinitionsLoadAndValidateThroughTheNativeProcessor(): void
    {
        $extensions = [new SafeGuardExtension()];
        foreach (['task-workflow', 'observational-memory'] as $package) {
            $manifest = json_decode(file_get_contents(ProjectDir::get().'/.hatfield/extensions/'.$package.'/composer.json'), true, flags: \JSON_THROW_ON_ERROR);
            $class = $manifest['extra']['hatfield']['extension'];
            $extension = new $class();
            $this->assertInstanceOf(ConfigurableExtensionInterface::class, $extension);
            $extensions[] = $extension;
        }
        /** @var array<string, ConfigurationInterface> $definitions */
        $definitions = [];
        foreach ($extensions as $extension) {
            $definition = $extension->getConfiguration();
            $definitions[$definition->getConfigTreeBuilder()->buildTree()->getName()] = $definition;
        }
        $this->assertSame(['safe_guard', 'task_workflow', 'observational_memory'], array_keys($definitions));
        $processor = new Processor();
        $patterns = ['echo hello, world', '  literal spacing  ', 'quote " and \\'];
        $safeGuard = $processor->processConfiguration($definitions['safe_guard'], [['allow_command_patterns' => $patterns, 'tool_names' => ['bash' => 'shell']]]);
        $this->assertSame($patterns, $safeGuard['allow_command_patterns']);
        $this->assertSame(['bash' => 'shell'], $safeGuard['tool_names']);
        $workflowDefaults = TaskWorkflowSettings::fromArray($processor->processConfiguration($definitions['task_workflow'], [[]]));
        $this->assertSame('', $workflowDefaults->repository);
        $workflow = TaskWorkflowSettings::fromArray($processor->processConfiguration($definitions['task_workflow'], [['repository' => 'acme/project', 'workflow_field' => 'Hatfield Status']]));
        $this->assertSame('acme/project', $workflow->repository);
        $this->assertSame('Hatfield Status', $workflow->workflowField);

        $input = [
            'librarian' => ['url' => 'http://localhost:8093/_mcp'],
        ];
        $om = $processor->processConfiguration($definitions['observational_memory'], [$input]);
        $settings = OmSettings::fromArray($om);
        $this->assertSame('http://localhost:8093/_mcp', $settings->librarian->url);
        $this->assertSame(30, $settings->librarian->timeoutSeconds);
        $this->assertNull($settings->librarian->token);
        $input['librarian'] = ['url' => 'https://user:secret@localhost/_mcp'];
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('without credentials');
        $processor->processConfiguration($definitions['observational_memory'], [$input]);
    }
}
