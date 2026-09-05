<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionModeEnum;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextSessionState;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Shared flock on read must wait for exclusive writers so readers never observe
 * the empty window between ftruncate and fwrite.
 */
final class JbcontextStatusStoreTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-store-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function sharedReadWaitsForExclusiveWriteAndSeesCommittedPayload(): void
    {
        $paths = JbcontextPaths::fromProjectRoot($this->projectDir);
        $sessionId = 'sess-lock';
        $store = JbcontextStatusStore::forSession($paths, $sessionId);
        $statusPath = $paths->sessionStatusPath($sessionId);
        $dir = \dirname($statusPath);
        $this->assertTrue(@mkdir($dir, 0o777, true) || is_dir($dir));

        $writer = fopen($statusPath, 'c+b');
        $this->assertNotFalse($writer);
        $this->assertTrue(flock($writer, \LOCK_EX));

        $autoload = ProjectDir::get().'/vendor/autoload.php';
        $this->assertFileExists($autoload);
        $readerScript = $this->projectDir.'/read-status.php';
        file_put_contents($readerScript, <<<'PHP'
<?php
declare(strict_types=1);

require $argv[1];

use Ineersa\HatfieldExt\Jbcontext\State\JbcontextPaths;
use Ineersa\HatfieldExt\Jbcontext\State\JbcontextStatusStore;

$store = JbcontextStatusStore::forSession(
    JbcontextPaths::fromProjectRoot($argv[2]),
    $argv[3],
);
$state = $store->read();
fwrite(STDOUT, json_encode($state->toArray(), JSON_THROW_ON_ERROR)."\n");
PHP);

        $pipes = [];
        $process = proc_open(
            [\PHP_BINARY, $readerScript, $autoload, $this->projectDir, $sessionId],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $status = proc_get_status($process);
        $this->assertIsArray($status);
        $this->assertGreaterThan(0, (int) ($status['pid'] ?? 0));

        $blocked = false;
        $deadline = microtime(true) + 1.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (\is_array($status) && true === ($status['running'] ?? false)) {
                $blocked = true;
                break;
            }
            usleep(5_000);
        }
        $this->assertTrue($blocked, 'Reader child must still be running while exclusive lock is held.');

        $payloadState = new JbcontextSessionState(
            sessionId: $sessionId,
            mode: JbcontextSessionModeEnum::Disabled,
            reason: 'no idea',
            statusText: 'jbcontext disabled: project has no .idea directory. Open the project in JetBrains IDE and run jbcontext index manually before enabling search.',
            attempt: 1,
            startedAt: 1.0,
            reindexPending: false,
            reindexRunning: false,
            eligibilityStarted: true,
            updatedAt: 2.0,
        );
        $payload = json_encode($payloadState->toArray(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)."\n";
        ftruncate($writer, 0);
        rewind($writer);
        fwrite($writer, $payload);
        fflush($writer);
        flock($writer, \LOCK_UN);
        fclose($writer);

        $stdout = '';
        $stderr = '';
        $exitDeadline = microtime(true) + 2.0;
        do {
            $chunkOut = stream_get_contents($pipes[1]);
            $chunkErr = stream_get_contents($pipes[2]);
            if (\is_string($chunkOut) && '' !== $chunkOut) {
                $stdout .= $chunkOut;
            }
            if (\is_string($chunkErr) && '' !== $chunkErr) {
                $stderr .= $chunkErr;
            }
            $status = proc_get_status($process);
            if (\is_array($status) && false === ($status['running'] ?? true)) {
                break;
            }
            usleep(5_000);
        } while (microtime(true) < $exitDeadline);

        $exitCode = proc_close($process);
        $this->assertSame(0, $exitCode, 'Reader child failed: '.$stderr);
        $decoded = json_decode(trim($stdout), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->assertSame(JbcontextSessionModeEnum::Disabled->value, $decoded['mode'] ?? null);
        $this->assertTrue((bool) ($decoded['eligibility_started'] ?? false));

        $state = $store->read();
        $this->assertSame(JbcontextSessionModeEnum::Disabled, $state->mode);
        $this->assertTrue($state->eligibilityStarted);
        $this->assertStringContainsString('no .idea directory', (string) $state->statusText);
    }
}
