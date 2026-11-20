<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Entity\CodeModeReportedReturnRepository;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use PHPUnit\Framework\Attributes\TestWith;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner */
final class CodeModeProcessOwnerTest extends IsolatedKernelTestCase
{
    #[TestWith(['return-first'])]
    #[TestWith(['exit-first'])]
    #[TestWith(['exit'])]
    #[TestWith(['expired'])]
    #[TestWith(['stop'])]
    #[TestWith(['legacy'])]
    public function testObservesSessionEvidenceWithoutSignallingFixtureProcesses(string $case): void
    {
        $root = TestDirectoryIsolation::createProjectTempDir('code-mode-observation');
        try {
            $fs = new Filesystem();
            $workspace = $root.'/workspace';
            $fs->mkdir($workspace);
            $returns = self::getContainer()->get(CodeModeReportedReturnRepository::class);
            $payloads = self::getContainer()->get(\Ineersa\AgentCore\Contract\Tool\ToolPayloadStoreInterface::class);
            $runId = self::getContainer()->get(\Ineersa\CodingAgent\Session\HatfieldSessionStore::class)->createSession('code-mode terminal reference');
            $record = static function () use ($payloads, $runId, $returns): void {
                $result = new \Ineersa\AgentCore\Domain\Message\ToolCallResult($runId, 1, 'step', 1, 'key', 'terminal', 0, ['ok' => true, 'result' => 42]);
                $returns->record('invocation', $payloads->publish($result));
            };
            $owner = new CodeModeProcessOwner(new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $root), new NullLogger(), new LockFactory(new FlockStore($root))), $returns, self::getContainer()->get(\Ineersa\CodingAgent\Entity\CodeModeCallRepository::class), $payloads);
            $prefix = $root.'/code-mode-'.hash('sha256', 'run').'-'.hash('sha256', 'invocation');
            $fs->dumpFile($prefix.'.workspace', json_encode([
                'workspace' => $workspace,
                'startedAtNs' => hrtime(true) - 10_000_000_000,
                'invocationId' => 'invocation',
                'controllerSettles' => 'legacy' !== $case,
            ], \JSON_THROW_ON_ERROR));
            $childPrefix = $root.'/code-mode-'.hash('sha256', 'run').'-'.hash('sha256', 'child');
            $fs->dumpFile($childPrefix.'.workspace', json_encode([
                'workspace' => $workspace, 'startedAtNs' => hrtime(true),
                'parent' => ['invocationId' => 'invocation', 'timeout' => 30],
            ], \JSON_THROW_ON_ERROR));
            $this->assertLessThan(hrtime(true), $owner->deadline('run', 'child', false, null, 1));
            $this->assertGreaterThan(hrtime(true), $owner->deadline('run', 'child', true, null, 1));
            $this->assertFalse($owner->hasExited('run', 'child'));
            $fs->dumpFile($childPrefix.'.status', "0\n");
            $this->assertTrue($owner->hasExited('run', 'child'));
            if ('return-first' === $case) {
                $record();
                $this->assertNull($owner->observeTerminal('run', 'invocation', null, null));
            }
            if ('stop' === $case || 'expired' === $case) {
                $fs->dumpFile($prefix.'.status', "143\n");
            } elseif ('legacy' !== $case) {
                $fs->dumpFile($prefix.'.status', "0\n");
            }
            if ('exit-first' === $case) {
                $record();
            }
            $expected = match ($case) {
                'return-first', 'exit-first' => ['ok' => true, 'result' => 42],
                'exit' => ['ok' => false, 'error' => 'Code-mode subprocess exited without returning a value.'],
                'expired' => ['ok' => false, 'error' => 'Code-mode invocation timed out.'],
                'stop' => ['ok' => false, 'error' => 'Code-mode invocation interrupted: stopped.'],
                default => null,
            };
            $this->assertSame('legacy' === $case ? [] : ['invocation'], iterator_to_array($owner->controllerInvocationIds('run')));
            $this->assertSame($expected, $owner->observeTerminal('run', 'invocation', 'expired' === $case ? 5 : null, 'stop' === $case ? 'stopped' : null));
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }
}
