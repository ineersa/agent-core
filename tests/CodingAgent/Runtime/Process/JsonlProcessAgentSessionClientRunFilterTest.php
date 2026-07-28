<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Process;

use Ineersa\CodingAgent\Runtime\Process\RuntimeEventPerRunCompactBuffer;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEvent;
use Ineersa\CodingAgent\Runtime\Protocol\RuntimeEventTypeEnum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: per-run compact buffer used by JsonlProcessAgentSessionClient demux
 * yields seq=0 extension_agent.job_failed for the matching run only.
 */
final class JsonlProcessAgentSessionClientRunFilterTest extends TestCase
{
    #[Test]
    public function compactBufferDrainsMatchingRunIncludingSeqZeroExtensionAgentFailure(): void
    {
        $buffer = new RuntimeEventPerRunCompactBuffer();

        $wanted = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ExtensionAgentJobFailed->value,
            runId: 'run-wanted',
            seq: 0,
            payload: [
                'message' => 'Extension background job failed after retrying.',
                'reason' => 'retry_exhausted',
                'handler_id' => 'observational_memory.reflect_generation',
                'job_id' => 'job-1',
                'retry_count' => 1,
                'attempts' => 2,
            ],
        );
        $other = new RuntimeEvent(
            type: RuntimeEventTypeEnum::ExtensionAgentJobFailed->value,
            runId: 'run-other',
            seq: 0,
            payload: [
                'message' => 'Extension background job failed after retrying.',
                'reason' => 'retry_exhausted',
                'handler_id' => 'observational_memory.observe_boundary',
                'job_id' => 'job-2',
                'retry_count' => 1,
                'attempts' => 2,
            ],
        );

        $buffer->ingest($other, observedRun: true);
        $buffer->ingest($wanted, observedRun: true);

        $yielded = iterator_to_array($buffer->drain('run-wanted'), false);
        $this->assertCount(1, $yielded);
        $this->assertSame('run-wanted', $yielded[0]->runId);
        $this->assertSame(0, $yielded[0]->seq);
        $this->assertSame(RuntimeEventTypeEnum::ExtensionAgentJobFailed->value, $yielded[0]->type);
        $this->assertSame('retry_exhausted', $yielded[0]->payload['reason']);

        $remainingOther = iterator_to_array($buffer->drain('run-other'), false);
        $this->assertCount(1, $remainingOther);
        $this->assertSame('run-other', $remainingOther[0]->runId);
    }
}
