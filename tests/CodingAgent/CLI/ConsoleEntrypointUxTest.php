<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\CLI;

use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * CLI entrypoint UX: default command is agent; framework commands stay callable
 * but are hidden from normal list output.
 *
 * Layer: subprocess against source bin/console (no TUI launch / no hang risk).
 * Thesis: --help with no command describes agent; list keeps Hatfield surface
 * and omits framework commands; messenger:consume remains resolvable by name.
 */
final class ConsoleEntrypointUxTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('console-entrypoint');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    #[Test]
    public function defaultHelpTargetsAgentAndListHidesFrameworkCommands(): void
    {
        $help = $this->runConsole(['--help']);
        $this->assertSame(0, $help->getExitCode(), $help->getErrorOutput().$help->getOutput());
        $helpOut = $help->getOutput().$help->getErrorOutput();
        $this->assertStringContainsString('Launch an interactive Hatfield coding-agent session', $helpOut);
        $this->assertStringContainsString('hatfield', $helpOut);
        $this->assertStringContainsString('hatfield list', $helpOut);
        $this->assertStringContainsString('hatfield help <command>', $helpOut);
        $this->assertStringContainsString('--controller', $helpOut);
        $this->assertStringNotContainsString('list [options]', $helpOut);
        $this->assertStringNotContainsString('display help for the list command', $helpOut);
        $this->assertStringContainsString('display help for the agent command', $helpOut);

        $list = $this->runConsole(['list', '--raw']);
        $this->assertSame(0, $list->getExitCode(), $list->getErrorOutput().$list->getOutput());
        $listOut = $list->getOutput();

        foreach ([
            'agent',
            'auth:codex',
            'completion:file-index:refresh',
            'log:clear',
            'log:files',
            'log:search',
            'log:tail',
            'session:cache:inspect',
            'help',
            'list',
            'completion',
        ] as $command) {
            $this->assertMatchesRegularExpression(
                '/^'.preg_quote($command, '/').'\b/m',
                $listOut,
                \sprintf('Visible command %s must appear in list --raw.', $command),
            );
        }

        foreach (['cache:clear', 'debug:container', 'doctrine:migrations:migrate', 'messenger:consume'] as $hidden) {
            $this->assertDoesNotMatchRegularExpression(
                '/^'.preg_quote($hidden, '/').'\b/m',
                $listOut,
                \sprintf('Framework command %s must be hidden from list --raw.', $hidden),
            );
        }
    }

    #[Test]
    public function hiddenMessengerConsumeRemainsCallableByName(): void
    {
        $process = $this->runConsole(['messenger:consume', '--help']);
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput().$process->getOutput());
        $out = $process->getOutput().$process->getErrorOutput();
        $this->assertStringContainsString('messenger:consume', $out);
        $this->assertStringContainsString('Consume messages', $out);
    }

    /**
     * @param list<string> $args
     */
    private function runConsole(array $args): Process
    {
        $cmd = array_merge(AgentTestExecutable::sourceConsoleCommand(), $args);
        $process = new Process(
            $cmd,
            cwd: $this->projectDir,
            env: [
                'APP_ENV' => 'prod',
                'APP_DEBUG' => '0',
                'HOME' => $this->projectDir.'/home',
                'HATFIELD_CACHE_DIR' => $this->projectDir.'/.hatfield/cache',
            ],
        );
        $process->setTimeout(30.0);
        $process->run();

        return $process;
    }
}
