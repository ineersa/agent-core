<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Castor;

use CastorTasks\TestArtifactLifecycle;
use Ineersa\CodingAgent\Tests\Support\ProjectDir;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

require_once \dirname(__DIR__, 3).'/.castor/TestArtifactLifecycle.php';

final class TestArtifactLifecycleTest extends TestCase
{
    private string $root;
    /** @var list<Process> */
    private array $processes = [];
    /** @var list<InputStream> */
    private array $inputs = [];

    protected function setUp(): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('Never signal root-owned processes.');
        }
        $this->root = TestDirectoryIsolation::createProjectTempDir('test-artifact-lifecycle');
    }

    protected function tearDown(): void
    {
        foreach ($this->inputs as $input) {
            $input->close();
        }
        foreach ($this->processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        if (isset($this->root)) {
            TestDirectoryIsolation::removeDirectory($this->root);
        }
        parent::tearDown();
    }

    /** @return iterable<string, array{int}> */
    public static function exits(): iterable
    {
        yield 'success' => [0];
        yield 'failure' => [1];
    }

    #[DataProvider('exits')]
    public function testShutdownReclaimsOwnedFixturesAndCachesOnBothExitCodes(int $exit): void
    {
        $process = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
foreach (['var/tmp/mcp-config-fixture', 'var/tmp/tw-code-fixture', 'var/tmp/tw-git-fixture', 'var/tmp/tui-fixture', 'var/tmp/hatfield-fixture', '.hatfield/cache-worker'] as $relative) {
    $path = $root.'/'.$relative;
    $a->own($path);
    mkdir($path, 0755, true);
    file_put_contents($path.'/proof', 'fixture');
}
$a->own($root.'/var/tmp/sg-proof.txt');
file_put_contents($root.'/var/tmp/sg-proof.txt', 'outside-CWD fixture');
exit((int) $argv[3]);
PHP, [(string) $exit]);
        $this->assertSame($exit, $process->run());
        $this->assertSame([], glob($this->root.'/var/tmp/*-fixture') ?: []);
        $this->assertFileDoesNotExist($this->root.'/var/tmp/sg-proof.txt');
        $this->assertDirectoryDoesNotExist($this->root.'/.hatfield/cache-worker');
        $this->assertSame([], glob($this->root.'/var/tmp/artifact-owners/*.json') ?: []);
        $this->assertSame('', $process->getErrorOutput());
    }

    public function testFinishClosesOnlyProducerLeaseWhileInheritedConsumerHoldsIt(): void
    {
        $producer = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$path = $root.'/var/tmp/fixture';
$a->own($path);
mkdir($path, 0755, true);
$input = new \Symfony\Component\Process\InputStream();
$script = 'foreach (glob("/proc/self/fd/*") as $fd) { $target = @readlink($fd); if (false !== $target && str_contains($target, "/artifact-owners/") && str_contains($target, "/sf.owner.")) { echo "LEASE_READY\n"; fflush(STDOUT); fgets(STDIN); exit(0); } } exit(2);';
$consumer = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script], env: ['HATFIELD_TEST_ARTIFACT_OWNER' => false], timeout: 3);
$consumer->setInput($input);
try {
    $consumer->start();
    if (!$consumer->waitUntil(static fn ($type, $output) => str_contains($output, 'LEASE_READY'))) { throw new \RuntimeException('Lease consumer not ready.'); }
    if ([] !== $a->finish() || !is_dir($path)) { throw new \RuntimeException('Producer finish unlocked the inherited consumer lease.'); }
    try {
        $a->own($root.'/var/tmp/pending');
        throw new \LogicException('A retained consumer allowed a new producer lifetime.');
    } catch (\RuntimeException $exception) {
        if (!str_contains($exception->getMessage(), 'prior consumer')) { throw $exception; }
        echo "test_artifact.retained_consumer_allocation_rejected\n";
    }
    echo "held\n";
    $input->close();
    if (0 !== $consumer->wait()) { throw new \RuntimeException('Inherited consumer exited with failure.'); }
    if ([$path, $root.'/var/tmp/pending'] !== $a->finish()) { throw new \RuntimeException('Stopped inherited consumer prevented recovery.'); }
    echo "reclaimed\n";
} finally {
    $input->close();
    $consumer->stop();
}
PHP);
        $producer->mustRun();
        $this->assertSame("test_artifact.retained_consumer_allocation_rejected\nheld\nreclaimed\n", $producer->getOutput());
        $this->assertSame('', $producer->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->root.'/var/tmp/fixture');
        $this->assertSame([], glob($this->root.'/var/tmp/artifact-owners/*.json') ?: []);
        $this->assertCount(1, glob($this->root.'/var/tmp/artifact-owners/*') ?: [], 'Only the permanent coordinator remains.');
    }

    public function testDetachedTmuxConsumerRetainsExplicitOwnerTokenWithoutLease(): void
    {
        if (!\Ineersa\Tui\Tests\E2E\TmuxHarness::isAvailable()) {
            $this->markTestSkipped('tmux is required to prove detached consumer ownership.');
        }
        $producer = $this->child(<<<'PHP'
putenv('HATFIELD_QA_RUN_ID=qa-detached-'.basename($root));
$parent = new \CastorTasks\TestArtifactLifecycle($root);
$staging = $root.'/var/tmp/qa-parent';
$parent->own($staging);
mkdir($staging, 0755, true);
$parent->report($root.'/var/reports/qa-parent');
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$path = $root.'/var/tmp/fixture';
$a->own($path);
mkdir($path, 0755, true);
$barrier = $root.'/consumer-barrier';
if (!posix_mkfifo($barrier, 0600)) { throw new \RuntimeException('Cannot create consumer barrier.'); }
$script = '$barrier = fopen($argv[1], "r+"); $leases = 0; foreach (glob("/proc/self/fd/*") as $fd) { $target = @readlink($fd); if (false !== $target && str_contains($target, "/artifact-owners/".getenv("HATFIELD_TEST_ARTIFACT_OWNER")."/") && str_contains($target, "/sf.owner.")) { ++$leases; } } file_put_contents($argv[2], json_encode(["pid" => getmypid(), "token" => getenv("HATFIELD_TEST_ARTIFACT_OWNER"), "leases" => $leases])); fgets($barrier);';
$command = 'unset HATFIELD_SESSION_ID; exec '.escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($script).' '.escapeshellarg($barrier).' '.escapeshellarg($root.'/consumer-ready');
$tmux = new \Ineersa\Tui\Tests\E2E\TmuxHarness();
try {
    $pane = $tmux->startDetached($command, 'test-artifact-token');
    $deadline = microtime(true) + 3;
    do {
        if (is_file($root.'/consumer-ready')) { break; }
        usleep(1000);
    } while (microtime(true) < $deadline);
    if (!is_file($root.'/consumer-ready')) { throw new \RuntimeException('Detached consumer not ready.'); }
    $proof = json_decode(file_get_contents($root.'/consumer-ready'), true, flags: JSON_THROW_ON_ERROR);
    if ($proof['token'] !== getenv('HATFIELD_TEST_ARTIFACT_OWNER') || $proof['leases'] !== 0) { throw new \RuntimeException('Detached token/lease proof failed.'); }
    // Creation-time environment remains ownership evidence if the producer
    // dies before tagging either session option. It also protects the QA parent.
    (new \Symfony\Component\Process\Process(['tmux', 'set-option', '-u', '-t', $pane->session, \CastorTasks\artifact_tmux_session_ownership_option()]))->mustRun();
    (new \Symfony\Component\Process\Process(['tmux', 'set-option', '-u', '-t', $pane->session, \CastorTasks\qa_tmux_session_ownership_option()]))->mustRun();
    if ([] !== $a->finish() || !is_dir($path)) { throw new \RuntimeException('Detached consumer token did not protect fixture.'); }
    if ([] !== $parent->finish() || !is_dir($staging)) { throw new \RuntimeException('Detached worker did not protect parent staging.'); }
    if (null !== json_decode(file_get_contents($root.'/var/reports/qa-parent/.artifact-owner.json'), true)['completed']) { throw new \RuntimeException('Detached worker allowed premature report completion.'); }
    echo "token-held\n";
} finally {
    $release = fopen($barrier, 'r+');
    fwrite($release, "\n");
    fclose($release);
    $tmux->killAll();
}
if ([$path] !== $a->finish()) { throw new \RuntimeException('Stopped detached consumer prevented recovery.'); }
if ([$staging] !== $parent->finish()) { throw new \RuntimeException('Stopped detached worker prevented parent cleanup.'); }
if (!is_numeric(json_decode(file_get_contents($root.'/var/reports/qa-parent/.artifact-owner.json'), true)['completed'])) { throw new \RuntimeException('Parent report did not complete.'); }
echo "reclaimed\n";
PHP);
        $producer->mustRun();
        $this->assertSame("token-held\nreclaimed\n", $producer->getOutput());
        $this->assertSame('', $producer->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->root.'/var/tmp/fixture');
        $this->assertCount(1, glob($this->root.'/var/tmp/artifact-owners/*') ?: []);
    }

    public function testUnavailableProcessIdentityAllowsNormalCleanupButNotAbandonedRecovery(): void
    {
        $producer = $this->child(<<<'PHP'
// Test-only namespace double models platforms without Linux process identity.
eval('namespace CastorTasks; function file_get_contents(string $path): string|false { return str_starts_with($path, "/proc/") ? false : \\file_get_contents($path); }');
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$path = $root.'/var/tmp/portable';
$a->own($path);
mkdir($path, 0755, true);
$a->report($root.'/var/reports/qa-portable');
if ([$path] !== $a->finish()) { throw new \RuntimeException('Portable normal cleanup failed.'); }
$marker = json_decode(file_get_contents($root.'/var/reports/qa-portable/.artifact-owner.json'), true);
if (null !== $marker['start'] || !is_numeric($marker['completed'])) { throw new \RuntimeException('Portable report did not complete.'); }
$stale = new \CastorTasks\TestArtifactLifecycle($root);
$stale->own($root.'/var/tmp/uncertain');
mkdir($root.'/var/tmp/uncertain', 0755, true);
unset($stale);
if ([] !== (new \CastorTasks\TestArtifactLifecycle($root))->recover()) { throw new \RuntimeException('Unavailable identity allowed abandoned recovery.'); }
echo "portable\n";
PHP);
        $producer->mustRun();
        $this->assertSame("portable\n", $producer->getOutput());
        $this->assertSame('', $producer->getErrorOutput());
        $this->assertDirectoryDoesNotExist($this->root.'/var/tmp/portable');
        $this->assertDirectoryExists($this->root.'/var/tmp/uncertain');
    }

    public function testFailedDetachedInventoryCannotAuthorizeCleanup(): void
    {
        $producer = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$path = $root.'/var/tmp/fixture';
$a->own($path);
mkdir($path, 0755, true);
\CastorTasks\TestArtifactLifecycle::registerCurrentTmuxOwner();
$bin = $root.'/tmux-bin';
mkdir($bin);
file_put_contents($bin.'/tmux', "#!/bin/sh\nexit 2\n");
chmod($bin.'/tmux', 0700);
putenv('PATH='.$bin);
if ([] !== $a->finish() || !is_dir($path)) { throw new \RuntimeException('Failed detached inventory authorized removal.'); }
echo "uncertain\n";
PHP);
        $producer->mustRun();
        $this->assertSame("uncertain\n", $producer->getOutput());
        $this->assertStringContainsString('test_artifact.tmux_inventory_failed exit_code=2', $producer->getErrorOutput());
        $this->assertDirectoryExists($this->root.'/var/tmp/fixture');
    }

    public function testSystemTemporaryDirectoryAliasUsesCanonicalOwnership(): void
    {
        $filesystem = new Filesystem();
        $filesystem->mkdir($this->root.'/system-tmp');
        $filesystem->symlink($this->root.'/system-tmp', $this->root.'/tmp-alias');
        $producer = $this->child(<<<'PHP'
$path = \Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::createOsTempDir('portable-temp');
if (!str_starts_with($path, $root.'/system-tmp/') || realpath($path) !== $path) { throw new \RuntimeException('System temp alias was not canonicalized.'); }
\Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation::removeDirectory($path);
echo "canonical\n";
PHP);
        $producer->setEnv(['HATFIELD_SESSION_ID' => false, 'HATFIELD_QA_RUN_ID' => false, 'TMPDIR' => $this->root.'/tmp-alias']);
        $producer->mustRun();
        $this->assertSame("canonical\n", $producer->getOutput());
        $this->assertSame('', $producer->getErrorOutput());
        $this->assertSame([], glob($this->root.'/system-tmp/*') ?: []);
    }

    public function testRemovedFixturesAreForgottenWithoutDroppingPendingAllocations(): void
    {
        $lifecycle = new TestArtifactLifecycle($this->root);
        $pending = $this->root.'/var/tmp/not-created-yet';
        $lifecycle->own($pending);
        for ($i = 0; $i < 50; ++$i) {
            $path = $this->root.'/var/tmp/fixture-'.$i;
            $lifecycle->own($path);
            (new Filesystem())->mkdir($path);
            $lifecycle->forgetRemovedPath($path);
            $manifest = (glob($this->root.'/var/tmp/artifact-owners/*.json') ?: [])[0];
            $owner = json_decode((string) file_get_contents($manifest), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertIsArray($owner);
            $this->assertSame([$pending, $path], $owner['paths']);
            TestArtifactLifecycle::removePath($path);
            $lifecycle->forgetRemovedPath($path);
        }
        $owner = json_decode((string) file_get_contents($manifest), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($owner);
        $this->assertSame([$pending], $owner['paths']);
        $this->assertSame([$pending], $lifecycle->finish());
        $new = $this->root.'/var/tmp/new-fixture';
        $lifecycle->own($new);
        $owner = json_decode((string) file_get_contents($manifest), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($owner);
        $this->assertSame([$new], $owner['paths'], 'Finished lifetimes do not accumulate already reclaimed paths.');
        $lifecycle->finish();
        $this->assertCount(1, glob($this->root.'/var/tmp/artifact-owners/*') ?: []);
    }

    public function testActiveOwnerIsProtectedAndCrashRecoveryIsIdempotent(): void
    {
        $process = $this->holdingOwner();
        $lifecycle = new TestArtifactLifecycle($this->root);
        $this->assertSame([], $lifecycle->recover());
        $this->assertFileExists($this->root.'/var/tmp/fixture/proof');
        $this->killOwned($process);
        $this->assertSame([$this->root.'/var/tmp/fixture'], $lifecycle->recover());
        $this->assertDirectoryDoesNotExist($this->root.'/var/tmp/fixture');
        $this->assertSame([], $lifecycle->recover());
        $locks = glob($this->root.'/var/tmp/artifact-owners/sf.*.lock') ?: [];
        $this->assertCount(1, $locks, 'One permanent coordination inode, not one lock file per artifact.');
    }

    public function testUnreapedZombieDoesNotMakeStoppedOwnershipUncertain(): void
    {
        $zombieParent = $this->child(<<<'PHP'
$script = 'echo "CHILD_READY\\n"; fflush(STDOUT); $end = microtime(true) + 3; while (!is_file($argv[1]) && microtime(true) < $end && posix_kill(posix_getppid(), 0)) { usleep(1000); }';
$child = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $script, $root.'/zombie-exit']);
$child->start();
$child->waitUntil(static fn ($type, $output) => str_contains($output, 'CHILD_READY'));
$pid = $child->getPid();
file_put_contents($root.'/zombie-exit', 'release');
// Do not call Process again until the barrier releases: status polling would
// reap the zombie and erase the Linux ownership uncertainty being proved.
$deadline = microtime(true) + 3;
do {
    $stat = @file_get_contents('/proc/'.$pid.'/stat');
    if (false !== $stat && preg_match('/\) Z /', $stat)) {
        echo "READY\n"; fflush(STDOUT); fgets(STDIN);
        $child->wait();
        exit(0);
    }
    usleep(1000);
} while (microtime(true) < $deadline);
exit(2);
PHP);
        $this->ready($zombieParent);
        $producer = $this->holdingOwner();
        $this->killOwned($producer);
        $this->assertSame([$this->root.'/var/tmp/fixture'], (new TestArtifactLifecycle($this->root))->recover());
    }

    public function testSurvivingConsumerTokenProtectsArtifactsAfterOwnerCrash(): void
    {
        $producer = $this->holdingOwner();
        $manifest = (glob($this->root.'/var/tmp/artifact-owners/*.json') ?: [])[0];
        $owner = json_decode((string) file_get_contents($manifest), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($owner);
        $this->assertIsString($owner['id']);
        $consumer = new Process([
            \PHP_BINARY, '-r', 'echo "READY\\n"; fflush(STDOUT); fgets(STDIN);',
        ], env: ['HATFIELD_SESSION_ID' => false, 'HATFIELD_TEST_ARTIFACT_OWNER' => $owner['id']]);
        $input = new InputStream();
        $this->inputs[] = $input;
        $consumer->setInput($input);
        $this->processes[] = $consumer;
        $consumer->start();
        $this->assertTrue($consumer->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')));
        $this->killOwned($producer);
        $lifecycle = new TestArtifactLifecycle($this->root);
        $this->assertSame([], $lifecycle->recover(), 'Consumer ownership does not rely on its CWD, descriptors or current argv referencing a fixture.');
        $this->assertDirectoryExists($this->root.'/var/tmp/fixture');
        $input->close();
        $consumer->wait();
        $this->assertSame([$this->root.'/var/tmp/fixture'], $lifecycle->recover());
    }

    public function testReportBoundRetainsActiveAndUnknownReports(): void
    {
        $active = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$a->report($root.'/var/reports/qa-active');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
PHP);
        $this->ready($active);
        (new Filesystem())->mkdir($this->root.'/var/reports/qa-unknown');
        $completed = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
for ($i = 0; $i < 13; ++$i) {
    $a->report($root.'/var/reports/qa-completed-'.sprintf('%02d', $i));
    $a->finish();
}
PHP);
        $completed->mustRun();
        $reports = glob($this->root.'/var/reports/qa-completed-*') ?: [];
        $this->assertCount(10, $reports);
        $this->assertDirectoryDoesNotExist($this->root.'/var/reports/qa-completed-02');
        $this->assertDirectoryExists($this->root.'/var/reports/qa-completed-03');
        $this->assertDirectoryExists($this->root.'/var/reports/qa-active');
        $this->assertDirectoryExists($this->root.'/var/reports/qa-unknown');
        $this->killOwned($active);
        (new TestArtifactLifecycle($this->root))->recover();
        $this->assertCount(11, glob($this->root.'/var/reports/qa-*') ?: [], '10 stopped reports plus unknown report.');
    }

    public function testRelativeReportEnvironmentProtectsConsumerAfterProducerCrash(): void
    {
        $producer = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$a->report($root.'/var/reports/qa-held');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
PHP);
        $this->ready($producer);
        $consumer = new Process([
            \PHP_BINARY, '-r', 'echo "READY\\n"; fflush(STDOUT); fgets(STDIN);',
        ], cwd: $this->root, env: [
            'HATFIELD_SESSION_ID' => false,
            'HATFIELD_TEST_ARTIFACT_OWNER' => false,
            'HATFIELD_QA_REPORTS_DIR' => 'var/reports/qa-held',
        ]);
        $this->processes[] = $consumer;
        $this->ready($consumer);
        $this->killOwned($producer);
        (new TestArtifactLifecycle($this->root))->recover();
        $marker = $this->root.'/var/reports/qa-held/.artifact-owner.json';
        $owner = json_decode((string) file_get_contents($marker), true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($owner);
        $this->assertNull($owner['completed'], 'Relative Castor report paths are active ownership evidence.');
    }

    public function testUnknownManifestAndSymlinkEscapeAreNotPermissionToDelete(): void
    {
        $filesystem = new Filesystem();
        $legacy = $this->root.'/var/tmp/mcp-config-legacy';
        $filesystem->mkdir($legacy);
        $lifecycle = new TestArtifactLifecycle($this->root);
        $filesystem->dumpFile($this->root.'/var/tmp/artifact-owners/invalid.json', '{bad json');
        $outside = TestDirectoryIsolation::createOsTempDir('test-artifact-outside');
        file_put_contents($outside.'/keep', 'unrelated');
        $filesystem->symlink($outside, $this->root.'/var/tmp/escape');
        try {
            $this->assertSame([], $lifecycle->recover());
            $this->assertDirectoryExists($legacy);
            $this->assertFileExists($outside.'/keep');
            $this->expectException(\InvalidArgumentException::class);
            $lifecycle->own($this->root.'/var/tmp/escape/nested');
        } finally {
            $filesystem->remove($this->root.'/var/tmp/escape');
            TestDirectoryIsolation::removeDirectory($outside);
        }
    }

    public function testDiagnosticArchiveSurvivesTemporaryFixtureCleanup(): void
    {
        $fixture = $this->root.'/var/tmp/tui-diagnostic';
        $lifecycle = new TestArtifactLifecycle($this->root);
        $lifecycle->own($fixture);
        (new Filesystem())->dumpFile($fixture.'/snapshot.ansi', 'screen proof');
        $this->assertTrue(posix_mkfifo($fixture.'/barrier.fifo', 0600));
        $archive = TestDirectoryIsolation::preserveDirectory($fixture, $this->root, $lifecycle);
        $lifecycle->finish();
        $this->assertDirectoryDoesNotExist($fixture);
        $this->assertSame('screen proof', file_get_contents($archive.'/snapshot.ansi'));
        $this->assertFileDoesNotExist($archive.'/barrier.fifo', 'Archive persistent proof, not live FIFO coordination.');
        $this->assertFileExists(\dirname($archive, 2).'/.artifact-owner.json');
    }

    private function holdingOwner(): Process
    {
        $process = $this->child(<<<'PHP'
$a = \CastorTasks\TestArtifactLifecycle::current($root);
$a->own($root.'/var/tmp/fixture');
mkdir($root.'/var/tmp/fixture', 0755, true);
file_put_contents($root.'/var/tmp/fixture/proof', 'fixture');
echo "READY\n"; fflush(STDOUT); fgets(STDIN);
PHP);
        $this->ready($process);

        return $process;
    }

    /** @param list<string> $arguments */
    private function child(string $body, array $arguments = []): Process
    {
        $autoload = ProjectDir::get().'/vendor/autoload.php';
        $class = ProjectDir::get().'/.castor/TestArtifactLifecycle.php';
        $script = 'require '.var_export($autoload, true).'; require '.var_export($class, true).'; $root = $argv[1];'.$body;
        // Isolated child processes own no real session; stop/kill cannot affect
        // root-owned or HATFIELD_SESSION_ID-tagged runtime workers.
        $process = new Process([\PHP_BINARY, '-r', $script, $this->root, 'unused', ...$arguments], env: ['HATFIELD_SESSION_ID' => false, 'HATFIELD_QA_RUN_ID' => false], timeout: 5);
        $this->processes[] = $process;

        return $process;
    }

    private function ready(Process $process): void
    {
        // A streaming input keeps the child alive behind a deterministic pipe barrier.
        $input = new InputStream();
        $this->inputs[] = $input;
        $process->setInput($input);
        $process->start();
        $this->assertTrue($process->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'READY')), $process->getErrorOutput());
    }

    private function killOwned(Process $process): void
    {
        if (0 === posix_geteuid()) {
            $this->markTestSkipped('Never signal root-owned processes.');
        }
        $process->signal(\SIGKILL);
        $process->wait();
    }
}
