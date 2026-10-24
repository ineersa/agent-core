<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller;

use Ineersa\CodingAgent\Runtime\Controller\HeadlessController;
use Ineersa\CodingAgent\Tests\Runtime\Controller\E2E\ControllerReplayE2eTestCase;
use Ineersa\CodingAgent\Tests\Support\AgentTestExecutable;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\Clock\MonotonicClock;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Controller ownership selectors must match the exact session and project.
 * Foreign brokers remain outside orphan-consumer cleanup selection.
 *
 * @group controller-replay
 */
#[Group('controller-replay')]
final class HeadlessControllerSessionOwnerLockProcessTest extends ControllerReplayE2eTestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function foreignBrokerIdentities(): iterable
    {
        yield 'session 1 must not select session 10 in the same project' => ['10', false];
        yield 'session 1 must not select session 1 in another project' => ['1', true];
    }

    /** @return iterable<string, array{string, bool, bool}> */
    public static function consumerIdentities(): iterable
    {
        yield 'exact session and project' => ["PATH=/bin\0HATFIELD_SESSION_ID=1\0OTHER=value\0", false, true];
        yield 'first environment entry' => ["HATFIELD_SESSION_ID=1\0PATH=/bin\0", false, true];
        yield 'session 10 is foreign' => ["HATFIELD_SESSION_ID=10\0", false, false];
        yield 'session 11 is foreign' => ["HATFIELD_SESSION_ID=11\0", false, false];
        yield 'session 100 is foreign' => ["HATFIELD_SESSION_ID=100\0", false, false];
        yield 'different environment key' => ["OTHER_HATFIELD_SESSION_ID=1\0", false, false];
        yield 'same session in foreign project' => ["HATFIELD_SESSION_ID=1\0", true, false];
        yield 'missing session identity' => ["PATH=/bin\0", false, false];
    }

    #[DataProvider('consumerIdentities')]
    public function testConsumerIdentityRequiresExactEnvironmentEntryAndProject(string $environment, bool $differentProject, bool $expected): void
    {
        $cwd = $differentProject ? TestDirectoryIsolation::createProjectTempDir('foreign-consumer') : $this->tempDir;
        try {
            // Exercise the production identity predicate without signaling tagged processes.
            $identity = new \ReflectionMethod(HeadlessController::class, 'matchesConsumerIdentity');
            $this->assertSame($expected, $identity->invoke(null, $environment, $cwd, '1', $this->tempDir));
        } finally {
            if ($differentProject) {
                TestDirectoryIsolation::removeDirectory($cwd);
            }
        }
    }

    #[DataProvider('foreignBrokerIdentities')]
    public function testOrphanConsumerSelectorsExcludeForeignBrokers(string $brokerSessionId, bool $differentProject): void
    {
        if ('Linux' !== \PHP_OS_FAMILY) {
            $this->markTestSkipped('The orphan consumer scanner uses Linux /proc.');
        }

        $cwd = $differentProject ? TestDirectoryIsolation::createProjectTempDir('foreign-broker') : $this->tempDir;
        [$php] = AgentTestExecutable::sourceConsoleCommand();
        $owner = new InputStream();
        // A broker-shaped fixture proves the actual pgrep selectors exclude it
        // before any PPID/environment checks, even if it later becomes orphaned.
        // Close stdin to release this session-tagged fixture; never signal it.
        $fixture = new Process(
            [$php, '-r', 'fwrite(STDOUT, "broker-fixture-ready\n"); while (false !== fgets(STDIN)) {}', 'sqlite-queue:broker', '--database='.$cwd.'/.hatfield/sessions/'.$brokerSessionId.'/queue.sqlite'],
            $cwd,
            ['HATFIELD_SESSION_ID' => $brokerSessionId],
            $owner,
            null,
        );

        try {
            $fixture->start();
            $clock = new MonotonicClock();
            $deadline = $clock->now()->modify('+5 seconds');
            $output = '';
            while ($fixture->isRunning() && $clock->now() < $deadline) {
                $output .= $fixture->getIncrementalOutput();
                if (str_contains($output, 'broker-fixture-ready')) {
                    break;
                }
                $clock->sleep(0.01);
            }
            $this->assertStringContainsString('broker-fixture-ready', $output);
            $pid = $fixture->getPid();
            $this->assertNotNull($pid);

            $visibleBroker = new Process(['pgrep', '-f', 'sqlite-queue:broker'], timeout: 5);
            $visibleBroker->mustRun();
            $this->assertContains((string) $pid, explode("\n", trim($visibleBroker->getOutput())), 'Positive control: pgrep can see the foreign broker fixture.');

            $patterns = new \ReflectionClass(HeadlessController::class)->getConstant('ORPHAN_CONSUMER_PATTERNS');
            $this->assertIsArray($patterns);
            $this->assertNotEmpty($patterns);
            foreach ($patterns as $pattern) {
                $this->assertIsString($pattern);
                $selection = new Process(['pgrep', '-f', $pattern], timeout: 5);
                $selection->run();
                $this->assertContains($selection->getExitCode(), [0, 1]);
                $this->assertNotContains((string) $pid, explode("\n", trim($selection->getOutput())));
            }
        } finally {
            $owner->close();
            if ($fixture->isStarted()) {
                $fixture->wait();
            }
            if ($differentProject) {
                TestDirectoryIsolation::removeDirectory($cwd);
            }
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        return [];
    }

    protected function tempDirPrefix(): string
    {
        return 'controller-session-owner-lock';
    }
}
