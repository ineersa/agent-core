<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI\Extensions;

use Ineersa\CodingAgent\CLI\Extensions\ExtensionsSetupFlow;
use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension;
use Ineersa\CodingAgent\Extension\Builtin\Scheduling\SchedulingExtension;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowConfiguration;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Yaml\Yaml;

final class ExtensionsSetupFlowTest extends IsolatedKernelTestCase
{
    public function testFreshProjectOffersBuiltinsAndSupportedOptionalPackages(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);

        $this->assertSame([
            'safe_guard' => ['label' => 'SafeGuard (built in)', 'installed' => true, 'class' => SafeGuardExtension::class],
            'scheduling' => ['label' => 'Scheduling (built in)', 'installed' => true, 'class' => SchedulingExtension::class],
            'ineersa/hatfield-ext-task-workflow' => ['label' => 'Task Workflow', 'installed' => false, 'class' => null],
            'ineersa/hatfield-ext-observational-memory' => ['label' => 'Observational Memory', 'installed' => false, 'class' => null],
        ], $flow->packages());
    }

    public function testMissingHookIsWrittenOnlyAsAValidCompoundValue(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
        $path = $this->isolatedCwd().'/.hatfield/settings.yaml';
        $this->assertSame($this->isolatedCwd().'/.hatfield', realpath(\dirname($path)));
        $this->assertFalse(is_link($path));
        $configuration = new TaskWorkflowConfiguration();
        foreach (['configuration' => $configuration, 'class' => 'fixture-task-workflow', 'key' => 'task_workflow'] as $name => $value) {
            (new \ReflectionProperty($flow, $name))->setValue($flow, $value);
        }
        $flow->members = ['parent' => 'false', 'forks' => 'off', 'agents' => 'false'];
        (new \ReflectionMethod($flow, 'collectNodes'))->invoke($flow, $configuration->getConfigTreeBuilder()->buildTree());
        $this->assertArrayHasKey('hooks.setup', $flow->nodes);
        $before = file_get_contents($path);
        try {
            $flow->save(['hooks.setup' => '{script: setup.sh}']);
            $this->fail('A hook requires a timeout.');
        } catch (InvalidConfigurationException $error) {
            $this->assertStringContainsString('timeout_seconds', $error->getMessage());
        }
        $this->assertSame($before, file_get_contents($path));
        $this->assertTrue($flow->save(['hooks.setup' => '{script: setup.sh, timeout_seconds: 12}']));
        $this->assertSame(['script' => 'setup.sh', 'timeout_seconds' => 12], Yaml::parseFile($path)['extensions']['settings']['task_workflow']['hooks']['setup']);
        $this->assertTrue($flow->save(['hooks.setup.timeout_seconds' => '15']));
        $this->assertSame(['script' => 'setup.sh', 'timeout_seconds' => 15], Yaml::parseFile($path)['extensions']['settings']['task_workflow']['hooks']['setup']);
    }

    public function testDisablingParentWithDefaultChildSelectionsIsRefused(): void
    {
        $flow = self::getContainer()->get(ExtensionsSetupFlow::class);
        $flow->select('safe_guard');
        $this->assertSame(['parent' => 'true', 'forks' => 'always_on', 'agents' => 'true'], $flow->members);
        $flow->members['parent'] = 'false';

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Enable the parent extension before selecting it for forks or agents.');
        $flow->save([]);
    }
}
