<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowConfiguration;
use Ineersa\HatfieldExt\TaskWorkflow\Settings\TaskWorkflowSettings;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class TaskWorkflowConfigurationTest extends TestCase
{
    public function testSchemaCarriesProjectSettingsAndHooks(): void
    {
        $config = (new Processor())->processConfiguration(new TaskWorkflowConfiguration(), [['repository' => 'github.example.com/acme/project', 'hooks' => ['setup' => ['script' => 'setup.sh', 'timeout_seconds' => 10], 'validate' => ['script' => 'validate.sh', 'timeout_seconds' => 20], 'cleanup' => ['script' => 'cleanup.sh', 'timeout_seconds' => 30]]]]);
        $settings = TaskWorkflowSettings::fromArray($config);
        $this->assertSame('github.example.com/acme/project', $settings->repository);
        $this->assertSame('origin', $settings->remote);
        $this->assertSame(20, $settings->hooks['validate']['timeout_seconds']);
    }

    public function testOldTaskRootIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new TaskWorkflowConfiguration(), [['task_root' => '/old']]);
    }

    public function testInvalidRepositoryIsRejected(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new Processor())->processConfiguration(new TaskWorkflowConfiguration(), [['repository' => 'https://github.com/acme/project']]);
    }
}
