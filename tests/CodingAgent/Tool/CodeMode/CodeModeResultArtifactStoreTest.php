<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool\CodeMode;

use Doctrine\DBAL\Connection;
use Ineersa\AgentCore\Application\Tool\StackToolExecutionContextAccessor;
use Ineersa\AgentCore\Application\Tool\ToolContext;
use Ineersa\AgentCore\Contract\Hook\NullCancellationToken;
use Ineersa\AgentCore\Contract\Tool\ToolCallException;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeExecutionService;
use Ineersa\CodingAgent\Application\CodeMode\CodeModeResultArtifactStore;
use Ineersa\CodingAgent\Entity\CodeModeExecutionRepository;
use Ineersa\CodingAgent\Session\Exception\ToolPayloadException;
use Ineersa\CodingAgent\Tests\Support\CodeModeBackendFixture;
use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\CodingAgent\Tests\TestCase\IsolatedKernelTestCase;
use Ineersa\CodingAgent\Tool\Arguments\CodeModeArgumentsDTO;
use Ineersa\CodingAgent\Tool\CodeModeTool;
use Ineersa\CodingAgent\Tool\OutputCap;

final class CodeModeResultArtifactStoreTest extends IsolatedKernelTestCase
{
    public function testFailedHostPreservesErrorAndDiagnosticsInsteadOfSuccessfulNull(): void
    {
        $script = 'echo "BEFORE"; fwrite(STDERR, "FAILED"); throw new RuntimeException("script exploded");';
        $f = new CodeModeBackendFixture(self::getContainer(), $script);
        try {
            $this->execute($f, $script);
            $this->fail('Script unexpectedly succeeded.');
        } catch (ToolCallException $error) {
            $this->assertStringContainsString('script exploded', $error->getMessage());
        }
        $row = self::getContainer()->get(CodeModeExecutionRepository::class)->invocation($f->state->runId, 'cm');
        $reference = self::getContainer()->get(CodeModeExecutionService::class)->resultReference($row['raw_reference']);
        $store = self::getContainer()->get(CodeModeResultArtifactStore::class);
        $result = $store->read($reference);
        $this->assertTrue($result->isError);
        $this->assertFalse($result->error['retryable']);
        $this->assertSame(ToolCallException::class, $result->error['type']);
        $this->assertArrayNotHasKey('code_mode_value', $result->result['details']);
        $this->assertStringContainsString('BEFORE', $result->error['message']);
        $this->assertStringContainsString('FAILED', $result->error['message']);
        $path = $store->path($reference);
        file_put_contents($path, str_repeat('X', $reference->bytes));
        $this->expectException(ToolPayloadException::class);
        $this->expectExceptionMessage('integrity');
        $store->read($reference);
    }

    public function testDiagnosticPromotionNeverOverwritesChangedRetainedBytes(): void
    {
        $c = self::getContainer();
        $fixture = new CodeModeBackendFixture($c, 'return 42;');
        $directory = TestDirectoryIsolation::createProjectTempDir('native-diagnostic');
        try {
            $source = $directory.'/stdout.log';
            file_put_contents($source, 'original diagnostic');
            $store = $c->get(CodeModeResultArtifactStore::class);
            $path = $store->retainDiagnostic($fixture->state->runId, 'cm', $source);
            $this->assertSame($path, $store->retainDiagnostic($fixture->state->runId, 'cm', $source));
            file_put_contents($path, 'changed diagnostic');
            $this->expectException(ToolPayloadException::class);
            $this->expectExceptionMessage('Immutable code-mode diagnostic has changed.');
            $store->retainDiagnostic($fixture->state->runId, 'cm', $source);
        } finally {
            TestDirectoryIsolation::removeDirectory($directory);
        }
    }

    public function testFailedHostRetainsOversizedDiagnosticBytesOutsideDisposableOutputCapStorage(): void
    {
        $c = self::getContainer();
        $script = 'for ($i=0; $i<160; ++$i) { fwrite(STDOUT, str_repeat("O",8192)); fwrite(STDERR, str_repeat("E",8192)); } throw new RuntimeException("large failure");';
        $fixture = new CodeModeBackendFixture($c, $script);
        try {
            $this->execute($fixture, $script);
            $this->fail('Script unexpectedly succeeded.');
        } catch (ToolCallException $error) {
            $this->assertStringContainsString('large failure', $error->getMessage());
        }
        $row = $c->get(CodeModeExecutionRepository::class)->invocation($fixture->state->runId, 'cm');
        $reference = $c->get(CodeModeExecutionService::class)->resultReference($row['raw_reference']);
        $result = $c->get(CodeModeResultArtifactStore::class)->read($reference);
        $this->assertTrue($result->isError);
        $this->assertSame(2, preg_match_all('/Saved full output: ([^\n]+)/', $result->error['message'], $matches));
        $c->get(OutputCap::class)->cleanupRun($fixture->state->runId, 'test');
        foreach ($matches[1] as $index => $path) {
            $this->assertStringContainsString('/code-mode-results/', $path);
            $this->assertSame(hash('sha256', str_repeat(0 === $index ? 'O' : 'E', 1310720)), hash_file('sha256', $path));
        }
    }

    public function testFailedArtifactRegistrationKeepsOnlyCopyAndReportsOwnedEvidencePath(): void
    {
        $c = self::getContainer();
        $script = 'echo "UNSEALED"; return ["answer"=>42];';
        $f = new CodeModeBackendFixture($c, $script);
        $db = $c->get(Connection::class);
        $db->executeStatement("CREATE TRIGGER fail_code_mode_raw BEFORE UPDATE OF raw_reference ON code_mode_execution BEGIN SELECT RAISE(ABORT, 'fixture artifact failure'); END");
        $evidence = null;
        try {
            try {
                $this->execute($f, $script);
                $this->fail('Artifact registration unexpectedly succeeded.');
            } catch (ToolCallException $error) {
                $this->assertSame('Code-mode terminal artifact could not be sealed.', $error->getMessage());
                $this->assertFalse($error->retryable());
                $this->assertStringStartsWith('Terminal evidence remains at ', $error->hint());
                $evidence = substr($error->hint(), \strlen('Terminal evidence remains at '));
            }
            $this->assertFileExists($evidence);
            $this->assertFileExists(\dirname($evidence).'/script.php');
            $snapshot = json_decode(file_get_contents($evidence), true, flags: \JSON_THROW_ON_ERROR);
            $this->assertSame(['answer' => 42], $snapshot['value']);
            $this->assertSame('UNSEALED', $snapshot['diagnostics']['stdout']);
            $this->assertNull($snapshot['failure']);
        } finally {
            $db->executeStatement('DROP TRIGGER fail_code_mode_raw');
            if (null !== $evidence) {
                TestDirectoryIsolation::removeDirectory(\dirname($evidence));
            }
        }
    }

    private function execute(CodeModeBackendFixture $f, string $script): mixed
    {
        $c = self::getContainer();

        return $c->get(StackToolExecutionContextAccessor::class)->with(new ToolContext($f->state->runId, 1, 'cm', 'code_mode', new NullCancellationToken(), 8, 0, null, 'step'), static fn () => ($c->get(CodeModeTool::class))(new CodeModeArgumentsDTO($script)));
    }
}
