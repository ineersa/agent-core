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
    #[TestWith(['abandoned'])]
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
            $container = self::getContainer();
            $store = $container->get(\Ineersa\CodingAgent\Tool\BackgroundProcess\ProcessStore::class);
            $owner = new CodeModeProcessOwner(new ProcessLifecycle(new BackgroundProcessConfig(storageDir: $root), new NullLogger(), new LockFactory(new FlockStore($root))), $returns, $container->get(\Ineersa\CodingAgent\Entity\CodeModeCallRepository::class), $payloads, $container->get(\Ineersa\CodingAgent\Entity\BackgroundProcessRepository::class), $store);
            $prefix = $root.'/code-mode-'.hash('sha256', 'run').'-'.hash('sha256', 'invocation');
            $store->insertRecord(['session_id' => 'run', 'code_mode_id' => 'invocation', 'code_mode_launch' => json_encode([
                'workspace' => $workspace, 'startedAtNs' => hrtime(true) - 10_000_000_000, 'parent' => null,
            ], \JSON_THROW_ON_ERROR)]);
            if ('return-first' === $case) {
                $record();
            }
            if ('stop' === $case || 'expired' === $case) {
                $fs->dumpFile($prefix.'.status', "143\n");
            } elseif ('abandoned' !== $case) {
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
                default => ['ok' => false, 'error' => 'Code-mode launch outcome is unknown; refusing to start it again.'],
            };
            $this->assertSame(['invocation'], $owner->controllerInvocationIds('run'));
            $this->assertSame($expected, $owner->observeTerminal('run', 'invocation', 'expired' === $case ? 5 : null, 'stop' === $case ? 'stopped' : null));
            if ('abandoned' === $case) {
                $this->assertTrue($owner->invocation('run', 'invocation')['fenced']);
                $this->assertSame($expected, $owner->observeTerminal('run', 'invocation', null, null));
                $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
                $owner->launch('run', 'invocation', $workspace, 'false', hrtime(true));
            }
        } finally {
            TestDirectoryIsolation::removeDirectory($root);
        }
    }
}
