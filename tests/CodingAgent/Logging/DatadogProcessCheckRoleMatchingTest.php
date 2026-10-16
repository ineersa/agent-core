<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use Ineersa\CodingAgent\Logging\ProcessRoleEnum;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves ops/datadog/process.d/conf.yaml role groups match attributed process
 * titles with Datadog Process Check semantics: exact_match false uses Python
 * re.search() against the joined command line / process title.
 */
#[CoversNothing]
final class DatadogProcessCheckRoleMatchingTest extends TestCase
{
    public function testRoleGroupsAreMutuallyExclusiveForAttributedProcessTitles(): void
    {
        $configPath = \dirname(__DIR__, 3).'/ops/datadog/process.d/conf.yaml';
        $config = Yaml::parseFile($configPath);
        $this->assertIsArray($config);
        $this->assertArrayHasKey('instances', $config);

        $patterns = [];
        foreach ($config['instances'] as $instance) {
            $this->assertIsArray($instance);
            $this->assertFalse($instance['exact_match'] ?? true);
            $this->assertNotSame('hatfield', $instance['name'] ?? null, 'Aggregate overlapping instance must stay removed.');
            $search = $instance['search_string'] ?? null;
            $this->assertIsArray($search);
            $this->assertCount(1, $search);
            $patterns[(string) $instance['name']] = (string) $search[0];
            $tags = $instance['tags'] ?? [];
            $this->assertContains('env:dev', $tags);
            $this->assertContains('app:hatfield', $tags);
            $this->assertTrue(
                [] !== array_filter($tags, static fn (mixed $tag): bool => \is_string($tag) && str_starts_with($tag, 'process_role:')),
                'Each instance needs an explicit process_role tag.',
            );
        }

        $expectedRoles = [
            'hatfield.screen' => ProcessRoleEnum::Screen,
            'hatfield.controller' => ProcessRoleEnum::Controller,
            'hatfield.headless' => ProcessRoleEnum::Headless,
            'hatfield.run_control' => ProcessRoleEnum::RunControl,
            'hatfield.llm' => ProcessRoleEnum::Llm,
            'hatfield.tool' => ProcessRoleEnum::Tool,
            'hatfield.agent_worker' => ProcessRoleEnum::AgentWorker,
            'hatfield.mcp' => ProcessRoleEnum::Mcp,
            'hatfield.extension_agent' => ProcessRoleEnum::ExtensionAgent,
            'hatfield.scheduler' => ProcessRoleEnum::Scheduler,
        ];
        $this->assertEqualsCanonicalizing(array_keys($expectedRoles), array_keys($patterns));
        $this->assertCount(\count($expectedRoles), $patterns);

        foreach ($expectedRoles as $expected => $role) {
            $title = $role->processTitle();
            $variants = [
                $title,
                $title."\0",
                'php '.$title,
                $title.' ',
            ];
            foreach ($variants as $command) {
                $hits = $this->matchingRoles($patterns, $command);
                $this->assertSame([$expected], $hits, $command);
            }
        }

        $negatives = [
            'php /home/x/bin/console agent',
            'php /home/x/hatfield.phar --resume=59',
            'php /home/x/bin/console agent --controller',
            'php /home/x/bin/console agent --prompt=--controller',
            'php /home/x/bin/console agent --prompt="use --headless later"',
            'php /home/x/bin/console messenger:consume run_control --no-interaction',
            'php /home/x/bin/console messenger:consume scheduler_default',
            'php /home/x/bin/console messenger:consume llm tool',
            'php /home/x/bin/console cache:clear',
            'hatfield.role.console',
            'hatfield.role.controllerish',
            'not-hatfield.role.controller',
        ];

        foreach ($negatives as $command) {
            $this->assertSame([], $this->matchingRoles($patterns, $command), $command);
        }
    }

    /**
     * @param array<string, string> $patterns
     *
     * @return list<string>
     */
    private function matchingRoles(array $patterns, string $commandLine): array
    {
        $hits = [];
        foreach ($patterns as $name => $pattern) {
            // Datadog uses Python re.search() with no PHP delimiters. Use '#' so
            // patterns that contain path slashes stay valid if restored later.
            if (1 === preg_match('#'.$pattern.'#', $commandLine)) {
                $hits[] = $name;
            }
        }

        return $hits;
    }
}
