<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\ObservationalMemory\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\HatfieldExt\ObservationalMemory\Claude\ProcessExec;
use PHPUnit\Framework\TestCase;

final class ProcessExecTest extends TestCase
{
    public function testCapturesOutputExitCodeCwdAndMergedEnvironment(): void
    {
        $result = (new ProcessExec())->exec('sh', ['-c', 'printf "%s|%s" "$PWD" "$OM_EXEC_TEST"; printf err >&2; exit 3'], new ExecOptionsDTO(cwd: '/', env: ['OM_EXEC_TEST' => 'value']));

        $this->assertSame('/|value', $result->stdout);
        $this->assertSame('err', $result->stderr);
        $this->assertSame(3, $result->exitCode);
        $this->assertFalse($result->timedOut);
    }

    public function testExhaustedBudgetStopsTheChild(): void
    {
        // The child would run far past the deadline; timing out is the asserted contract.
        $result = (new ProcessExec())->exec('sleep', ['30'], new ExecOptionsDTO(timeout: 0.0));

        $this->assertTrue($result->timedOut);
        $this->assertSame(-1, $result->exitCode);
    }

    public function testStartFailureIsAStructuredResult(): void
    {
        $result = (new ProcessExec())->exec('sh', ['-c', 'true'], new ExecOptionsDTO(cwd: '/nonexistent-om-exec-cwd'));

        $this->assertSame(-1, $result->exitCode);
        $this->assertNotSame('', $result->stderr);
    }
}
