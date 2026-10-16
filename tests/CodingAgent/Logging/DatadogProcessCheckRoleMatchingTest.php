<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Logging;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Proves ops/datadog/process.d/conf.yaml role groups match real launch shapes
 * with Datadog Process Check semantics: exact_match false uses Python
 * re.search() against the joined command line.
 */
#[CoversNothing]
final class DatadogProcessCheckRoleMatchingTest extends TestCase
{
    public function testRoleGroupsAreMutuallyExclusiveForRealLaunchShapes(): void
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

        $cases = [
            'hatfield.screen' => [
                'php /home/x/bin/console agent',
                'php /home/x/bin/console agent --cwd=/tmp --prompt=hi',
                '/opt/hatfield.phar agent',
            ],
            'hatfield.controller' => [
                'php /home/x/bin/console agent --controller',
                'php /home/x/bin/console agent --cwd=/tmp --controller',
                '/opt/hatfield.phar agent --controller --cwd=/tmp',
            ],
            'hatfield.headless' => [
                'php /home/x/bin/console agent --headless',
                '/opt/hatfield.phar agent --headless',
            ],
            'hatfield.run_control' => [
                'php /home/x/bin/console messenger:consume run_control --no-interaction --memory-limit=256M',
                '/opt/hatfield.phar messenger:consume run_control',
            ],
            'hatfield.llm' => [
                'php /home/x/bin/console messenger:consume llm --sleep=0.05',
            ],
            'hatfield.tool' => [
                'php /home/x/bin/console messenger:consume tool',
            ],
            'hatfield.agent_worker' => [
                'php /home/x/bin/console messenger:consume agent --no-interaction',
            ],
            'hatfield.mcp' => [
                'php /home/x/bin/console messenger:consume mcp',
            ],
            'hatfield.extension_agent' => [
                'php /home/x/bin/console messenger:consume extension_agent',
            ],
        ];

        $negatives = [
            'php /home/x/bin/console cache:clear',
            'php /home/x/bin/console agents:init',
            'php /home/x/bin/console list',
            'php /home/x/bin/console about',
            'php /home/x/bin/console messenger:consume unknown_transport',
        ];

        foreach ($cases as $expected => $commands) {
            $this->assertArrayHasKey($expected, $patterns);
            foreach ($commands as $command) {
                $hits = $this->matchingRoles($patterns, $command);
                $this->assertSame([$expected], $hits, $command);
            }
        }

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
            // patterns that contain bin/console path slashes stay valid.
            if (1 === preg_match('#'.$pattern.'#', $commandLine)) {
                $hits[] = $name;
            }
        }

        return $hits;
    }
}
