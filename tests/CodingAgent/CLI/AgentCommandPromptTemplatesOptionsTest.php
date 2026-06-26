<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\PromptTemplate\PromptTemplatesRuntimeConfig;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;

/**
 * @covers \Ineersa\CodingAgent\CLI\AgentCommand
 *
 * Tests that the agent command exposes --prompt-template and
 * --no-prompt-templates options, and that PromptTemplatesRuntimeConfig
 * (the shared mutable config populated by AgentCommand) is wired into
 * the container and can accept values.
 */
final class AgentCommandPromptTemplatesOptionsTest extends IsolatedKernelTestCase
{
    // ── Option existence ───────────────────────────────────────────

    public function testCommandHasPromptTemplateOption(): void
    {
        $app = $this->getApplication();
        $command = $app->find('agent');

        self::assertTrue($command->getDefinition()->hasOption('prompt-template'));
        self::assertTrue($command->getDefinition()->hasOption('no-prompt-templates'));
    }

    public function testPromptTemplateOptionIsRepeatableArray(): void
    {
        $app = $this->getApplication();
        $command = $app->find('agent');

        $option = $command->getDefinition()->getOption('prompt-template');
        self::assertTrue($option->isArray(), '--prompt-template should accept array values');
    }

    public function testNoPromptTemplatesHasNoShortcut(): void
    {
        $app = $this->getApplication();
        $command = $app->find('agent');

        $option = $command->getDefinition()->getOption('no-prompt-templates');
        self::assertSame('', $option->getShortcut() ?? '', '--no-prompt-templates should have no shortcut');
    }

    // ── Config population (simulating what AgentCommand does) ──────

    public function testConfigDefaultsToEmpty(): void
    {
        /** @var PromptTemplatesRuntimeConfig $config */
        $config = self::getContainer()->get(PromptTemplatesRuntimeConfig::class);
        self::assertSame([], $config->promptTemplatePaths);
        self::assertFalse($config->noPromptTemplates);
    }

    public function testCanPopulatePromptTemplatePaths(): void
    {
        /** @var PromptTemplatesRuntimeConfig $config */
        $config = self::getContainer()->get(PromptTemplatesRuntimeConfig::class);
        $config->promptTemplatePaths = ['/one.md', '/two.md'];

        self::assertSame(['/one.md', '/two.md'], $config->promptTemplatePaths);
    }

    public function testCanSetNoPromptTemplates(): void
    {
        /** @var PromptTemplatesRuntimeConfig $config */
        $config = self::getContainer()->get(PromptTemplatesRuntimeConfig::class);
        $config->noPromptTemplates = true;

        self::assertTrue($config->noPromptTemplates);
    }

    public function testCombinedConfig(): void
    {
        /** @var PromptTemplatesRuntimeConfig $config */
        $config = self::getContainer()->get(PromptTemplatesRuntimeConfig::class);
        $config->promptTemplatePaths = ['/a.md'];
        $config->noPromptTemplates = true;

        self::assertSame(['/a.md'], $config->promptTemplatePaths);
        self::assertTrue($config->noPromptTemplates);
    }

    public function testControllerArgsOutputOrder(): void
    {
        /** @var PromptTemplatesRuntimeConfig $config */
        $config = self::getContainer()->get(PromptTemplatesRuntimeConfig::class);
        $config->noPromptTemplates = true;
        $config->promptTemplatePaths = ['/first.md', '/second.md'];

        $args = $config->controllerArgs();
        self::assertSame([
            '--no-prompt-templates',
            '--prompt-template=/first.md',
            '--prompt-template=/second.md',
        ], $args);
    }

    // ── Helpers ────────────────────────────────────────────────────

    private function getApplication(): \Symfony\Component\Console\Application
    {
        $kernel = self::$kernel;

        return new \Symfony\Bundle\FrameworkBundle\Console\Application($kernel);
    }
}
