<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Ineersa\CodingAgent\Config\BackgroundProcessConfig;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessLifecycle;
use Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\Store\FlockStore;

/** @covers \Ineersa\CodingAgent\Tool\CodeMode\CodeModeProcessOwner */
final class CodeModeProcessOwnerTest extends TestCase
{
    #[TestWith(['return'])]
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
            $owner = new CodeModeProcessOwner(new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $root), new NullLogger(), new LockFactory(new FlockStore($root))));
            $prefix = $root.'/code-mode-'.hash('sha256', 'run').'-'.hash('sha256', 'invocation');
            $fs->dumpFile($prefix.'.workspace', json_encode([
                'workspace' => $workspace,
                'startedAtNs' => hrtime(true) - 10_000_000_000,
                'invocationId' => 'invocation',
                'controllerSettles' => 'legacy' !== $case,
            ], \JSON_THROW_ON_ERROR));
            if ('exit' === $case) {
                $fs->dumpFile($prefix.'.status', "0\n");
            }
            if ('return' === $case) {
                $fs->dumpFile($workspace.'/terminal.json', '{"ok":true,"result":42}');
            }
            $expected = match ($case) {
                'return' => ['ok' => true, 'result' => 42],
                'exit' => ['ok' => false, 'error' => 'Code-mode subprocess exited without returning a value.'],
                'expired' => ['ok' => false, 'error' => 'Code-mode invocation timed out.'],
                'stop' => ['ok' => false, 'error' => 'Code-mode invocation interrupted: stopped.'],
                default => null,
            };
            $this->assertSame('legacy' === $case ? [] : ['invocation'], iterator_to_array($owner->controllerInvocationIds('run')));
            $this->assertSame($expected, $owner->observeTerminal('run', 'invocation', 'expired' === $case ? 5 : null, 'stop' === $case ? 'stopped' : null));
            $this->assertSame($expected, $owner->observeTerminal('run', 'invocation', null, null));
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }
}
