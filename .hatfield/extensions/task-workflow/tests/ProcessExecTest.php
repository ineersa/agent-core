<?php

declare(strict_types=1);

namespace Ineersa\HatfieldExt\TaskWorkflow\Tests;

use Ineersa\Hatfield\ExtensionApi\Exec\ExecOptionsDTO;
use Ineersa\Hatfield\ExtensionApi\Tool\ToolCancellationTokenInterface;
use Ineersa\HatfieldExt\TaskWorkflow\Claude\ProcessExec;
use PHPUnit\Framework\TestCase;

final class ProcessExecTest extends TestCase
{
    public function testCapturesOutputExitCodeCwdAndMergedEnvironment(): void
    {
        $result = (new ProcessExec())->exec('sh', ['-c', 'printf "%s|%s" "$PWD" "$TASK_WORKFLOW_TEST"; printf err >&2; exit 3'], new ExecOptionsDTO(cwd: '/', env: ['TASK_WORKFLOW_TEST' => 'value']));

        $this->assertSame('/|value', $result->stdout);
        $this->assertSame('err', $result->stderr);
        $this->assertSame(3, $result->exitCode);
        $this->assertFalse($result->timedOut);
        $this->assertFalse($result->cancelled);
    }

    public function testStopsAtDeadline(): void
    {
        // The child would run far past the deadline; timing out is the asserted contract.
        $result = (new ProcessExec())->exec('sleep', ['30'], new ExecOptionsDTO(timeout: 0.0));

        $this->assertTrue($result->timedOut);
        $this->assertFalse($result->cancelled);
        $this->assertNotSame(0, $result->exitCode);
    }

    public function testStopsWhenCancellationIsRequested(): void
    {
        $token = new class implements ToolCancellationTokenInterface {
            public function isCancellationRequested(): bool
            {
                return true;
            }
        };

        $result = (new ProcessExec())->exec('sleep', ['30'], new ExecOptionsDTO(cancellationToken: $token));

        $this->assertTrue($result->cancelled);
        $this->assertFalse($result->timedOut);
    }

    public function testReportsMissingCwdAsStructuredFailure(): void
    {
        $result = (new ProcessExec())->exec('true', [], new ExecOptionsDTO(cwd: '/nonexistent/task-workflow-test'));

        $this->assertSame(-1, $result->exitCode);
        $this->assertNotSame('', $result->stderr);
    }
}
