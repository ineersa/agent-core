<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\HatfieldExt\ObservationalMemory\Tests\Support\OmTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

/**
 * Covers claude/bin, the plugin's MCP server and hook entry points.
 */
final class ObservationalMemoryPluginLauncherTest extends OmTestCase
{
    #[DataProvider('entryPoints')]
    public function testRefusesUnexpandedOrRelativeDataDirectoryWithoutWritingAnything(string $entryPoint): void
    {
        foreach (['${CLAUDE_PLUGIN_DATA}', 'relative/data', ''] as $dataDir) {
            $process = new Process([\PHP_BINARY, \dirname(__DIR__).'/claude/bin/'.$entryPoint], $this->directory, ['CLAUDE_PLUGIN_DATA' => $dataDir, 'CLAUDE_PROJECT_DIR' => $this->directory, 'COMPOSER_BINARY' => '/nonexistent/composer'], '{}', 10.0);
            $process->run();

            $this->assertSame(1, $process->getExitCode(), $dataDir);
            $this->assertStringContainsString('CLAUDE_PLUGIN_DATA must be an absolute path', $process->getErrorOutput());
            // stdout is the MCP stream, or for a hook, text that could reach the conversation.
            $this->assertSame('', $process->getOutput());
            $this->assertSame([], array_values(array_diff((array) scandir($this->directory), ['.', '..'])));
        }
    }

    public static function entryPoints(): iterable
    {
        yield 'mcp server' => ['observational-memory-mcp'];
        yield 'hook' => ['observational-memory-hook'];
    }
}
