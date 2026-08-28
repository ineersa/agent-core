<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Tools;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/** Exercises the opt-in tool's aggregate-only process boundary, not replay internals. */
final class StorageReplayBenchmarkTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('storage-replay-benchmark');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    #[Test]
    public function reportsFixedAggregateReplayResultsWithoutReadingOrChangingInputFiles(): void
    {
        $parent = $this->projectDir.'/.hatfield/sessions/parent-valid/events.jsonl';
        $child = $this->projectDir.'/.hatfield/sessions/parent-valid/artifacts/agents/child-sentinel/events.jsonl';
        foreach ([$parent, $child] as $path) {
            mkdir(\dirname($path), 0777, true);
        }

        file_put_contents($parent, $this->jsonl([
            $this->event(1, 'run_started', ['payload' => ['metadata' => ['model' => 'model-sentinel']]]),
        ]));
        file_put_contents($child, $this->jsonl([
            $this->event(1, 'run_started', ['payload' => ['metadata' => ['model' => 'child-model-sentinel']]]),
        ]));
        $before = [
            $parent => [file_get_contents($parent), filemtime($parent)],
            $child => [file_get_contents($child), filemtime($child)],
        ];

        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 3).'/tools/storage-replay-benchmark.php',
            $this->projectDir.'/.hatfield',
        ]);
        $process->setTimeout(10.0);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertMatchesRegularExpression('/^SCHEMA benchmark=1 privacy=aggregate-only measurement=[a-z0-9_]+ peak=[a-z0-9_]+\n/', $output);
        $this->assertMatchesRegularExpression('/^DATASET scope=parent event_files=1 event_bytes=\d+\nREPLAY_RESULT scope=parent status=measured candidates_skipped=0 events=1 duration_ms=\d+ peak_memory_delta_bytes=\d+\n/m', $output);
        $this->assertMatchesRegularExpression('/^DATASET scope=child event_files=1 event_bytes=\d+\nREPLAY_RESULT scope=child status=measured candidates_skipped=0 events=1 duration_ms=\d+ peak_memory_delta_bytes=\d+\n/m', $output);
        foreach (['parent-valid', 'child-sentinel', 'model-sentinel', 'child-model-sentinel'] as $sentinel) {
            $this->assertStringNotContainsString($sentinel, $output);
        }
        foreach ($before as $path => [$contents, $mtime]) {
            $this->assertSame($contents, file_get_contents($path));
            $this->assertSame($mtime, filemtime($path));
        }
    }

    #[Test]
    public function skipsMalformedTypedCurrentPayloadWithoutDisclosingIt(): void
    {
        $path = $this->projectDir.'/.hatfield/sessions/parent-invalid/events.jsonl';
        mkdir(\dirname($path), 0777, true);
        file_put_contents($path, $this->jsonl([
            $this->event(1, 'tool_execution_end', ['tool_result' => ['invalid' => 'malformed-payload-sentinel']]),
        ]));

        $process = new Process([
            \PHP_BINARY,
            \dirname(__DIR__, 3).'/tools/storage-replay-benchmark.php',
            $this->projectDir.'/.hatfield',
        ]);
        $process->setTimeout(10.0);
        $process->run();

        $output = $process->getOutput().$process->getErrorOutput();
        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('REPLAY_RESULT scope=parent status=skipped_unreplayable candidates_skipped=1 events=0', $output);
        $this->assertStringNotContainsString('parent-invalid', $output);
        $this->assertStringNotContainsString('malformed-payload-sentinel', $output);
    }

    /** @param array<string, mixed> $payload */
    private function event(int $seq, string $type, array $payload): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => 'run-id-sentinel',
            'seq' => $seq,
            'turn_no' => 0,
            'type' => $type,
            'payload' => $payload,
            'ts' => '2026-01-01T00:00:00+00:00',
        ];
    }

    /** @param list<array<string, mixed>> $events */
    private function jsonl(array $events): string
    {
        return implode('', array_map(
            static fn (array $event): string => json_encode($event, \JSON_THROW_ON_ERROR)."\n",
            $events,
        ));
    }
}
