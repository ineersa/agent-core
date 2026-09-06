<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\Jbcontext\Tests;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use Ineersa\Hatfield\ExtensionApi\Exec\ExecResultDTO;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCli;
use Ineersa\HatfieldExt\Jbcontext\Cli\JbcontextCliDiagnostic;
use Ineersa\HatfieldExt\Jbcontext\Tests\Support\RecordingExec;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JbcontextCliTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->projectDir = TestDirectoryIsolation::createOsTempDir('jbcontext-cli-');
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
        parent::tearDown();
    }

    #[Test]
    public function statusPassesBoundedStderrDetailWithStableEmptyStdoutCode(): void
    {
        $stderr = "Authentication required\nfrom ai.grazie.indexing.code.cli.command.util.IsLoggedInGuard";
        $exec = new RecordingExec([
            new ExecResultDTO(
                stdout: '',
                stderr: $stderr,
                exitCode: 1,
            ),
        ]);
        $cli = new JbcontextCli($exec, $this->projectDir);

        $result = $cli->status();

        $this->assertFalse($result['ok']);
        $this->assertSame('empty_stdout', $result['error']);
        $this->assertSame(JbcontextCliDiagnostic::format($stderr), $result['detail']);
        $this->assertNull($result['payload']);
    }

    #[Test]
    public function statusKeepsEmptyStderrFallbackWithoutDetail(): void
    {
        $exec = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: '', exitCode: 1),
        ]);
        $cli = new JbcontextCli($exec, $this->projectDir);

        $result = $cli->status();

        $this->assertFalse($result['ok']);
        $this->assertSame('empty_stdout', $result['error']);
        $this->assertNull($result['detail']);
    }

    #[Test]
    public function statusPreservesCancellationAndTimeoutWithoutDetail(): void
    {
        $cancelled = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'ignored', exitCode: 130, timedOut: false, cancelled: true),
        ]);
        $timedOut = new RecordingExec([
            new ExecResultDTO(stdout: '', stderr: 'ignored', exitCode: 124, timedOut: true, cancelled: false),
        ]);

        $cancelledResult = (new JbcontextCli($cancelled, $this->projectDir))->status();
        $timedOutResult = (new JbcontextCli($timedOut, $this->projectDir))->status();

        $this->assertTrue($cancelledResult['cancelled']);
        $this->assertSame('cancelled', $cancelledResult['error']);
        $this->assertNull($cancelledResult['detail']);

        $this->assertTrue($timedOutResult['timed_out']);
        $this->assertSame('timed_out', $timedOutResult['error']);
        $this->assertNull($timedOutResult['detail']);
    }
}
