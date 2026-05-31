<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Extension\Builtin\SafeGuard;

use Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SessionEventReader;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SessionEventReader — verifies reading approval answers
 * from the session's events.jsonl file.
 */
final class SessionEventReaderTest extends TestCase
{
    private string $tmpDir;

    /**
     * Create a test session directory under $tmpDir/.hatfield/sessions/<id>/.
     */
    private function createSessionDir(string $sessionId): string
    {
        $dir = $this->tmpDir . '/.hatfield/sessions/' . $sessionId;
        mkdir($dir, 0o755, true);

        return $dir;
    }

    private function writeEvents(string $sessionId, string $jsonl): void
    {
        $dir = $this->createSessionDir($sessionId);
        file_put_contents($dir . '/events.jsonl', $jsonl);
    }

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/sg_event_reader_' . uniqid();
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->tmpDir)) {
            return;
        }

        $it = new \RecursiveDirectoryIterator($this->tmpDir, \RecursiveDirectoryIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            if ($file->isDir()) {
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }
        rmdir($this->tmpDir);
    }

    public function testFindAnswerReturnsAnswerForMatchingQuestionId(): void
    {
        $this->writeEvents('run-123', implode("\n", [
            '{"type":"run_started","payload":{}}',
            '{"type":"waiting_human","payload":{"question_id":"q-abc"}}',
            '{"type":"agent_command_applied","payload":{"kind":"human_response","question_id":"q-abc","answer":"Allow once"}}',
        ]));

        $reader = new SessionEventReader($this->tmpDir);
        $this->assertSame('Allow once', $reader->findAnswer('run-123', 'q-abc'));
    }

    public function testFindAnswerReturnsNullWhenNoMatch(): void
    {
        $this->writeEvents('run-123', implode("\n", [
            '{"type":"agent_command_applied","payload":{"kind":"human_response","question_id":"q-other","answer":"Deny"}}',
        ]));

        $reader = new SessionEventReader($this->tmpDir);
        $this->assertNull($reader->findAnswer('run-123', 'q-abc'));
    }

    public function testFindAnswerReturnsNullWhenNoEventsFile(): void
    {
        $reader = new SessionEventReader($this->tmpDir);
        $this->assertNull($reader->findAnswer('run-nonexistent', 'q-abc'));
    }

    public function testFindAnswerReturnsNullForEmptyAnswer(): void
    {
        $this->writeEvents('run-123', implode("\n", [
            '{"type":"agent_command_applied","payload":{"kind":"human_response","question_id":"q-abc","answer":""}}',
        ]));

        $reader = new SessionEventReader($this->tmpDir);
        $this->assertNull($reader->findAnswer('run-123', 'q-abc'));
    }

    public function testFindAnswerReturnsNullForNonHumanResponseEvents(): void
    {
        $this->writeEvents('run-123', implode("\n", [
            '{"type":"agent_command_applied","payload":{"kind":"continue","question_id":"q-abc","answer":"yes"}}',
        ]));

        $reader = new SessionEventReader($this->tmpDir);
        $this->assertNull($reader->findAnswer('run-123', 'q-abc'));
    }

    public function testFindAnswerFindsNewestMatchWhenMultiple(): void
    {
        $this->writeEvents('run-123', implode("\n", [
            '{"type":"agent_command_applied","payload":{"kind":"human_response","question_id":"q-abc","answer":"Deny"}}',
            '{"type":"agent_command_applied","payload":{"kind":"human_response","question_id":"q-abc","answer":"Allow once"}}',
        ]));

        $reader = new SessionEventReader($this->tmpDir);
        $this->assertSame('Allow once', $reader->findAnswer('run-123', 'q-abc'));
    }

    public function testFindAnswerHandlesLargeFile(): void
    {
        // Generate events across multiple 8KB chunks to test backwards scanning
        $lines = [];
        for ($i = 0; $i < 1000; ++$i) {
            $lines[] = '{"type":"turn_completed","payload":{"turn":' . $i . '}}';
        }
        $lines[] = '{"type":"agent_command_applied","payload":{"kind":"human_response","question_id":"q-large","answer":"Always allow"}}';
        $this->writeEvents('run-123', implode("\n", $lines));

        $reader = new SessionEventReader($this->tmpDir);
        $this->assertSame('Always allow', $reader->findAnswer('run-123', 'q-large'));
    }
}
