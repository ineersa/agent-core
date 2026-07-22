<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension;

use Ineersa\CodingAgent\Config\AppConfig;
use Ineersa\CodingAgent\Config\ExtensionsConfig;
use Ineersa\CodingAgent\Config\LoggingConfig;
use Ineersa\CodingAgent\Config\TuiConfig;
use Ineersa\CodingAgent\Extension\ExtensionExecBridge;
use Ineersa\CodingAgent\Extension\ExtensionHookRegistry;
use Ineersa\CodingAgent\Extension\ExtensionManager;
use Ineersa\CodingAgent\Extension\ExtensionToolRegistryBridge;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use Ineersa\HatfieldExt\TaskWorkflow\TaskWorkflowExtension;
use Ineersa\Tui\Command\SlashCommandRegistry;
use Ineersa\Tui\Extension\TuiCommandRegistryAdapter;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Proves TaskWorkflowExtension connects through the production ExtensionManager
 * and ExtensionToolRegistryBridge (not the in-memory test bridge).
 */
final class TaskWorkflowExtensionIntegrationTest extends TestCase
{
    private string $taskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->taskRoot = TestDirectoryIsolation::createProjectTempDir('task-board');
        foreach (['TODO', 'IN-PROGRESS', 'CODE-REVIEW', 'DONE', 'ARCHIVE', 'CANCELLED'] as $status) {
            TestDirectoryIsolation::ensureDirectory($this->taskRoot.'/'.$status);
        }
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->taskRoot);
        parent::tearDown();
    }

    public function testTaskWorkflowExtensionLoadsToolsAndSlashCommands(): void
    {
        $toolRegistry = new ToolRegistry();
        $slashRegistry = new SlashCommandRegistry();
        $commandAdapter = new TuiCommandRegistryAdapter($slashRegistry);
        $hookRegistry = new ExtensionHookRegistry();
        $execBridge = new ExtensionExecBridge();

        $projectDir = \Ineersa\CodingAgent\Tests\Support\ProjectDir::get();
        $appConfig = new AppConfig(
            tui: new TuiConfig(theme: 'default'),
            logging: new LoggingConfig(),
            extensions: new ExtensionsConfig(
                enabled: [TaskWorkflowExtension::class],
                settings: [
                    'task_workflow' => [
                        'task_root' => $this->taskRoot,
                    ],
                ],
            ),
            cwd: $projectDir,
        );

        $bridge = new ExtensionToolRegistryBridge(
            $toolRegistry,
            $hookRegistry,
            $appConfig,
            $execBridge,
            $commandAdapter,
        );

        $manager = new ExtensionManager($appConfig, $bridge, new NullLogger());
        $diagnostics = $manager->loadExtensions();

        $this->assertSame([], $diagnostics, 'Extension must register without failures: '.implode('; ', $diagnostics));

        $toolNames = $toolRegistry->activeToolNames();
        foreach (['task_list', 'create_task', 'move_task', 'update_task'] as $expected) {
            $this->assertContains($expected, $toolNames, 'Missing tool: '.$expected);
        }

        foreach (['tasks', 'tasks-todo', 'tasks-in-progress', 'tasks-code-review', 'tasks-done'] as $command) {
            $this->assertTrue($slashRegistry->has($command), 'Missing slash command: '.$command);
        }

        $definitions = [];
        foreach ($toolRegistry->activeToolDefinitions() as $definition) {
            $definitions[$definition->name] = $definition;
        }

        $this->assertArrayHasKey('task_list', $definitions);
        $listSchema = $definitions['task_list']->parametersJsonSchema;
        $this->assertArrayHasKey('include_archive', $listSchema['properties'] ?? []);
        $this->assertContains('ARCHIVE', $listSchema['properties']['status']['enum'] ?? []);
        $this->assertContains('CANCELLED', $listSchema['properties']['status']['enum'] ?? []);

        $this->assertArrayHasKey('move_task', $definitions);
        $moveSchema = $definitions['move_task']->parametersJsonSchema;
        $this->assertContains('ARCHIVE', $moveSchema['properties']['to']['enum'] ?? []);
        $this->assertContains('CANCELLED', $moveSchema['properties']['to']['enum'] ?? []);
    }
}
