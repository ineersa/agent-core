<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\Hatfield\ExtensionApi\ConfigurableExtensionInterface;
use Ineersa\HatfieldExt\ObservationalMemory\Runtime\OmSettings;
use Ineersa\HatfieldExt\ObservationalMemory\Semantic\SemanticSettings;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ExtensionConfigurationTest extends TestCase
{
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
            'model' => 'local/Qwen/Qwen3',
            'observer' => ['renderer_version' => 'custom'],
            'custom-internal-key' => ['untouched' => true],
            'semantic' => [
                'embedding_api' => ['base_url' => 'http://localhost:8080/v1', 'model_id' => 'embedding/model'],
                'reranker_api' => ['base_url' => 'https://localhost/v1', 'model_id' => 'reranker/model', 'min_score' => -0.5],
            ],
        ];
        $om = $processor->processConfiguration($definitions['observational_memory'], [$input]);
        $settings = OmSettings::fromArray($om);
        $this->assertSame('local/Qwen/Qwen3', $settings->model);
        $this->assertSame('custom', $settings->rendererVersion);
        $this->assertSame(['untouched' => true], $om['custom-internal-key']);
        $this->assertSame(SemanticSettings::DEFAULT_CHUNK_BYTES, $settings->semantic?->chunkBytes);
        $this->assertSame(-0.5, $settings->semantic?->rerankerMinScore);
        $root = $definitions['observational_memory']->getConfigTreeBuilder()->buildTree();
        $this->assertTrue($root->getChildren()['model']->getAttribute('hatfield.model_picker'));
        $input['semantic']['reranker_api'] = null;
        $this->assertNull(OmSettings::fromArray($processor->processConfiguration($definitions['observational_memory'], [$input]))->semantic?->rerankerUrl);
        $input['semantic'] = null;
        $this->assertNull(OmSettings::fromArray($processor->processConfiguration($definitions['observational_memory'], [$input]))->semantic);

        $input['semantic'] = ['embedding_api' => ['base_url' => 'https://user:secret@localhost/v1', 'model_id' => 'embedding/model']];
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('without credentials');
        $processor->processConfiguration($definitions['observational_memory'], [$input]);
    }
}
