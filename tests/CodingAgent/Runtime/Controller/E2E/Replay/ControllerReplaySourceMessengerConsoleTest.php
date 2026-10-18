<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * @covers \Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\Replay\ControllerReplaySourceMessengerConsole
 */
final class ControllerReplaySourceMessengerConsoleTest extends TestCase
{
    #[Test]
    public function installWritesPassThroughWrapperWithoutSleepMutation(): void
    {
        $dir = TestDirectoryIsolation::createOsTempDir('replay-source-console');
        $probe = $dir.'/probe-console.php';
        file_put_contents($probe, <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
fwrite(STDOUT, json_encode([
    'argv' => $GLOBALS['argv'] ?? [],
    'server_session' => $_SERVER['HATFIELD_SESSION_ID'] ?? null,
    'environ_session' => getenv('HATFIELD_SESSION_ID'),
], JSON_THROW_ON_ERROR));
PHP);
        @chmod($probe, 0o755);

        try {
            $wrapper = ControllerReplaySourceMessengerConsole::install($dir, $probe);
            $this->assertFileExists($wrapper);
            $source = (string) file_get_contents($wrapper);
            $this->assertStringNotContainsString('--sleep=', $source);
            $this->assertStringContainsString('HATFIELD_REPLAY_SESSION_ID', $source);

            $process = new Process(
                [\PHP_BINARY, $wrapper, 'messenger:consume', 'run_control', '--keepalive=5'],
                $dir,
                [
                    'HATFIELD_REPLAY_SESSION_ID' => 'e2e-native-wait-proof',
                    'HATFIELD_SESSION_ID' => false,
                ],
                timeout: 5,
            );
            $process->mustRun();
            /** @var array{argv: list<string>, server_session: ?string, environ_session: string|false|null} $payload */
            $payload = json_decode($process->getOutput(), true, 512, \JSON_THROW_ON_ERROR);

            $this->assertSame('e2e-native-wait-proof', $payload['server_session']);
            $this->assertFalse($payload['environ_session']);
            $this->assertContains('messenger:consume', $payload['argv']);
            $this->assertContains('run_control', $payload['argv']);
            $this->assertContains('--keepalive=5', $payload['argv']);
            $this->assertFalse(array_reduce(
                $payload['argv'],
                static fn (bool $found, mixed $arg): bool => $found || (\is_string($arg) && (str_starts_with($arg, '--sleep=') || '--sleep' === $arg)),
                false,
            ));
        } finally {
            TestDirectoryIsolation::removeDirectory($dir);
        }
    }
}
