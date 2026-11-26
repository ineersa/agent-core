<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/** @covers \Ineersa\CodingAgent\CLI\AgentCommand */
final class AgentCommandPromptTemplatesOptionsTest extends IsolatedKernelTestCase
{
    public function testCommandExposesRepeatablePromptTemplateAndDisableOptions(): void
    {
        $application = new \Symfony\Bundle\FrameworkBundle\Console\Application(self::$kernel);
        $definition = $application->find('agent')->getDefinition();

        $this->assertTrue($definition->getOption('prompt-template')->isArray());
        $this->assertNull($definition->getOption('no-prompt-templates')->getShortcut());
    }

    public function testControllerArgsOutputOrder(): void
    {
        $config = new PromptTemplatesRuntimeConfig();
        $config->noPromptTemplates = true;
        $config->promptTemplatePaths = ['/first.md', '/second.md'];

        $args = $config->controllerArgs();
        $this->assertSame([
            '--no-prompt-templates',
            '--prompt-template=/first.md',
            '--prompt-template=/second.md',
        ], $args);
    }
}
