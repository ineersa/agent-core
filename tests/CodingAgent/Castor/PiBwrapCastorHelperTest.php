<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

use function CastorTasks\build_pi_bwrap_castor_reexec_argv;
use function CastorTasks\build_pi_bwrap_castor_reexec_command;
use function CastorTasks\castor_cli_executable;
use function CastorTasks\pi_bwrap_already_inside;
use function CastorTasks\pi_bwrap_disabled_by_env;
use function CastorTasks\should_auto_wrap_agent_castor_task;

require_once __DIR__.'/../../../.castor/helpers.php';

#[Group('unit')]
final class PiBwrapCastorHelperTest extends TestCase
{
    private const REEXEC_STUB_EXIT = 77;

    private array $envBackup = [];

    /** @var list<string> */
    private array $stubTempDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->envBackup as $name => $value) {
            if (false === $value) {
                putenv($name);
                unset($_ENV[$name], $_SERVER[$name]);
            } else {
                putenv($name.'='.$value);
                $_ENV[$name] = $value;
                $_SERVER[$name] = $value;
            }
        }
        $this->envBackup = [];

        foreach ($this->stubTempDirs as $dir) {
            if (is_dir($dir)) {
                TestDirectoryIsolation::removeDirectory($dir);
            }
        }
        $this->stubTempDirs = [];

        parent::tearDown();
    }

    public function testPiBwrapDisabledByEnv(): void
    {
        $this->setEnv('HATFIELD_BWRAP', '0');
        $this->assertTrue(pi_bwrap_disabled_by_env());
        $this->setEnv('HATFIELD_BWRAP', 'false');
        $this->assertTrue(pi_bwrap_disabled_by_env());
        $this->setEnv('HATFIELD_BWRAP', null);
        $this->assertFalse(pi_bwrap_disabled_by_env());
    }

    public function testPiBwrapAlreadyInside(): void
    {
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', '1');
        $this->assertTrue(pi_bwrap_already_inside());
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', null);
        $this->assertFalse(pi_bwrap_already_inside());
    }

    public function testShouldAutoWrapSkipsWhenDisabledOrInside(): void
    {
        $this->setEnv('HATFIELD_BWRAP', '0');
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', null);
        $this->assertFalse(should_auto_wrap_agent_castor_task());

        $this->setEnv('HATFIELD_BWRAP', null);
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', '1');
        $this->assertFalse(should_auto_wrap_agent_castor_task());
    }

    public function testBuildReexecArgvUsesSeparateTokensNotOneQuotedInnerCommand(): void
    {
        [$stub] = $this->createStubWrapperScript();
        $this->setEnv('HATFIELD_PI_BWRAP', $stub);
        $this->setEnv('HATFIELD_BWRAP', null);
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', null);

        $castorBin = castor_cli_executable();
        $this->assertNotNull($castorBin, 'castor CLI must be resolvable for re-exec argv test');

        $argv = build_pi_bwrap_castor_reexec_argv('run:agent');
        $this->assertNotNull($argv);
        $this->assertCount(5, $argv);
        $this->assertSame($stub, $argv[0]);
        $this->assertSame('env', $argv[1]);
        $this->assertSame('HATFIELD_INSIDE_PI_BWRAP=1', $argv[2]);
        $this->assertSame($castorBin, $argv[3]);
        $this->assertSame('run:agent', $argv[4]);

        $command = build_pi_bwrap_castor_reexec_command('run:agent');
        $this->assertNotNull($command);
        $this->assertStringContainsString("'".$stub."'", $command);
        $this->assertStringContainsString("'env'", $command);
        $this->assertStringContainsString("'HATFIELD_INSIDE_PI_BWRAP=1'", $command);
        $this->assertStringContainsString("'".$castorBin."'", $command);
        $this->assertStringContainsString("'run:agent'", $command);

        // Old bug: entire inner shell string quoted as a single bwrap argv.
        $this->assertDoesNotMatchRegularExpression(
            "/'env HATFIELD_INSIDE_PI_BWRAP=1/",
            $command,
            'Inner command must not be one quoted blob passed to pi-bwrap',
        );
    }

    public function testBuildReexecReturnsNullWhenDisabled(): void
    {
        $this->setEnv('HATFIELD_BWRAP', '0');
        $this->assertNull(build_pi_bwrap_castor_reexec_argv('run:agent'));
        $this->assertNull(build_pi_bwrap_castor_reexec_command('run:agent'));
    }

    public function testMaybeReexecUsesArgvVectorEndToEnd(): void
    {
        [$stub, $logPath] = $this->createStubWrapperScript();
        $this->setEnv('HATFIELD_PI_BWRAP', $stub);
        $this->setEnv('HATFIELD_BWRAP', null);
        $this->setEnv('HATFIELD_INSIDE_PI_BWRAP', null);

        $castorBin = castor_cli_executable();
        $this->assertNotNull($castorBin);

        $cmd = \sprintf(
            'cd %s && %s run:agent 2>/dev/null',
            escapeshellarg(getcwd() ?: '.'),
            escapeshellarg($castorBin),
        );

        $output = [];
        $exitCode = 0;
        exec($cmd, $output, $exitCode);

        $this->assertSame(self::REEXEC_STUB_EXIT, $exitCode, 'Stub wrapper should exit before tmux; output: '.implode("\n", $output));

        $this->assertFileExists($logPath);
        $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $this->assertNotFalse($lines);
        $this->assertGreaterThanOrEqual(4, \count($lines));
        $this->assertSame('env', $lines[0]);
        $this->assertSame('HATFIELD_INSIDE_PI_BWRAP=1', $lines[1]);
        $this->assertSame($castorBin, $lines[2]);
        $this->assertSame('run:agent', $lines[3]);

    }

    /**
     * @return array{0: string, 1: string} stub path, argv log path
     */
    private function createStubWrapperScript(): array
    {
        $dir = TestDirectoryIsolation::createProjectTempDir('pi-bwrap-stub');
        $this->stubTempDirs[] = $dir;
        $log = $dir.'/argv.log';
        $stub = $dir.'/pi-bwrap-stub.sh';
        $exit = self::REEXEC_STUB_EXIT;
        $content = <<<BASH
#!/usr/bin/env bash
set -euo pipefail
: > "{$log}"
while [[ \$# -gt 0 ]]; do
  printf '%s\\n' "\$1" >> "{$log}"
  shift
done
exit {$exit}
BASH;
        file_put_contents($stub, $content);
        chmod($stub, 0755);

        return [$stub, $log];
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (!\array_key_exists($name, $this->envBackup)) {
            $this->envBackup[$name] = getenv($name);
        }
        if (null === $value) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        } else {
            putenv($name.'='.$value);
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
        }
    }
}