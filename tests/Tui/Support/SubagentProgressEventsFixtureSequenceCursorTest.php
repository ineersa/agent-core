<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\TestCase;

final class SubagentProgressEventsFixtureSequenceCursorTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = TestDirectoryIsolation::createProjectTempDir('subagent-progress-fixture-cursor');
        TestDirectoryIsolation::createHatfieldTree($this->projectDir, withSessions: true);
    }

    protected function tearDown(): void
    {
        TestDirectoryIsolation::removeDirectory($this->projectDir);
    }

    public function testWriteAdvancesSequenceCursorPastFixtureMaxSeq(): void
    {
        $sessionId = '1';
        $sessionDir = $this->projectDir.'/.hatfield/sessions/'.$sessionId;
        mkdir($sessionDir, 0o777, true);

        // Live bootstrap turn leaves a stale low cursor before fixtures overwrite events.jsonl.
        file_put_contents(
            $sessionDir.'/events.jsonl',
            '{"schema_version":"1.0","run_id":"1","seq":1,"turn_no":0,"type":"run_started","payload":{},"ts":"t"}'."\n"
            .'{"schema_version":"1.0","run_id":"1","seq":2,"turn_no":1,"type":"turn_advanced","payload":{},"ts":"t"}'."\n"
            .'{"schema_version":"1.0","run_id":"1","seq":3,"turn_no":1,"type":"history_position_set","payload":{},"ts":"t"}'."\n"
            .'{"schema_version":"1.0","run_id":"1","seq":4,"turn_no":1,"type":"llm_step_completed","payload":{},"ts":"t"}'."\n",
        );
        file_put_contents($sessionDir.'/sequence.cursor', "4\n");

        SubagentProgressEventsFixture::write($this->projectDir, $sessionId);

        $this->assertSame("12\n", file_get_contents($sessionDir.'/sequence.cursor'));

        // Stale-cursor append that previously reissued fixture seq 5/6 must now land after maxSeq.
        file_put_contents(
            $sessionDir.'/events.jsonl',
            json_encode([
                'schema_version' => '1.0',
                'run_id' => $sessionId,
                'seq' => 13,
                'turn_no' => 1,
                'type' => 'tool_execution_start',
                'payload' => ['race' => true],
                'ts' => 't',
            ], \JSON_THROW_ON_ERROR)."\n",
            \FILE_APPEND,
        );

        SubagentChildHitlEventsFixture::write($this->projectDir, $sessionId);

        $seqs = [];
        foreach (file($sessionDir.'/events.jsonl', \FILE_IGNORE_NEW_LINES) as $line) {
            if ('' === $line) {
                continue;
            }
            $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $seq = (int) $row['seq'];
            $seqs[$seq] = ($seqs[$seq] ?? 0) + 1;
        }

        $duplicates = array_keys(array_filter($seqs, static fn (int $count): bool => $count > 1));
        $this->assertSame([], $duplicates, 'Parent fixture write+patch must not leave duplicate sequence numbers');
        $this->assertSame("12\n", file_get_contents($sessionDir.'/sequence.cursor'));
        $this->assertArrayHasKey(9, $seqs);
        $this->assertSame('waiting_human', $this->terminalProgressStatus($sessionDir.'/events.jsonl'));
    }

    private function terminalProgressStatus(string $eventsPath): ?string
    {
        $status = null;
        foreach (file($eventsPath, \FILE_IGNORE_NEW_LINES) as $line) {
            if ('' === $line) {
                continue;
            }
            $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $progress = $row['payload']['subagent_progress'] ?? null;
            if (\is_array($progress) && isset($progress['status'])) {
                $status = (string) $progress['status'];
            }
        }

        return $status;
    }
}
