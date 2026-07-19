<?php

declare(strict_types=1);

namespace Ineersa\Tui\Tests\Support;

/**
 * Parent/child fixture for SafeGuard-style child tool_question needs-input E2E.
 *
 * Parent subagent_progress stays {@code running} (not canonical waiting_human) so the
 * TUI must latch needs-input from transient {@code tool_question.requested}, proving
 * the race where stale running progress must not erase the latched state.
 */
final class SubagentChildSafeguardNeedsInputFixture
{
    public const string ARTIFACT_ID = 'agent_e2e_sg_needs_input';
    public const string TOOL_CALL_ID = 'call_child_write_sg';
    public const string REQUEST_ID = 'sg_child_needs_input_e2e';
    public const string PROMPT = 'SafeGuard: allow write outside CWD for the scout child?';

    public static function childRunId(string $sessionId): string
    {
        return $sessionId.'_child_scout_sg_001';
    }

    public static function write(string $projectDir, string $sessionId): void
    {
        SubagentProgressEventsFixture::write($projectDir, $sessionId);

        $artifactId = self::ARTIFACT_ID;
        $childRunId = self::childRunId($sessionId);
        $parentDir = $projectDir.'/.hatfield/sessions/'.$sessionId;
        $agentsDir = $parentDir.'/artifacts/agents';
        $artifactDir = $agentsDir.'/'.$artifactId;
        foreach ([$agentsDir, $artifactDir] as $dir) {
            if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new \RuntimeException('Failed to create dir: '.$dir);
            }
        }

        $now = (new \DateTimeImmutable())->format(\DATE_ATOM);
        $artifactRel = 'artifacts/agents/'.$artifactId;

        $registry = [
            'schema_version' => 1,
            'entries' => [[
                'artifact_id' => $artifactId,
                'parent_run_id' => $sessionId,
                'agent_run_id' => $childRunId,
                'agent_name' => 'scout',
                'kind' => 'subagent',
                'status' => 'running',
                'created_at' => $now,
                'paths' => [
                    'artifact_dir' => $artifactRel,
                    'metadata_path' => $artifactRel.'/metadata.json',
                    'handoff_path' => $artifactRel.'/handoff.md',
                    'events_path' => $artifactRel.'/events.jsonl',
                    'state_path' => $artifactRel.'/state.json',
                ],
            ]],
        ];
        file_put_contents($agentsDir.'/registry.json', json_encode($registry, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT));

        // Child canonical log stays mid-tool (no waiting_human / no tool terminal).
        $childEvents = [];
        $childEvents[] = self::childEvent($childRunId, 1, 0, 'run_started', ['step_id' => 'cstart'], $now);
        $childEvents[] = self::childEvent($childRunId, 2, 1, 'tool_execution_start', [
            'tool_call_id' => self::TOOL_CALL_ID,
            'tool_name' => 'write',
            'order_index' => 0,
            'mode' => 'sequential',
        ], $now);
        $lines = implode("\n", array_map(static fn (array $e): string => json_encode($e, \JSON_THROW_ON_ERROR), $childEvents))."\n";
        file_put_contents($artifactDir.'/events.jsonl', $lines);

        // Rewrite parent progress rows onto this artifact/run and keep status running.
        $parentEventsPath = $parentDir.'/events.jsonl';
        $parentLines = file($parentEventsPath, \FILE_IGNORE_NEW_LINES);
        if (false === $parentLines) {
            throw new \RuntimeException('Failed to read parent events: '.$parentEventsPath);
        }

        $patched = [];
        foreach ($parentLines as $line) {
            $row = json_decode($line, true, 512, \JSON_THROW_ON_ERROR);
            $progress = $row['payload']['subagent_progress'] ?? null;
            if (\is_array($progress)) {
                $progress['artifact_id'] = $artifactId;
                $progress['agent_run_id'] = $childRunId;
                $progress['agent_name'] = 'scout';
                $progress['task_summary'] = 'Child SafeGuard needs-input fixture';
                // Keep nonterminal running so needs-input must come from tool_question latch.
                if (($progress['status'] ?? '') === 'completed') {
                    $progress['status'] = 'running';
                }
                $row['payload']['subagent_progress'] = $progress;
            }
            $patched[] = json_encode($row, \JSON_THROW_ON_ERROR);
        }
        file_put_contents($parentEventsPath, implode("\n", $patched)."\n");
    }

    /**
     * Insert a pending un-emitted SafeGuard-like enum tool_question for the child run.
     *
     * Must be called after the agent/controller is running so startup cleanup does not
     * cancel a pre-start row, and with a fresh created_at.
     */
    public static function seedPendingToolQuestion(string $appDbAbsolutePath, string $childRunId): void
    {
        $pdo = new \PDO('sqlite:'.$appDbAbsolutePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        // Schema source of truth: Ineersa\CodingAgent\Entity\ToolQuestion plus
        // migrations Version20260606140000 (create) and Version20260617141002
        // (answer_text + schema). Keep this DDL aligned when the entity changes.
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tool_question (
                id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
                request_id VARCHAR(255) NOT NULL,
                run_id VARCHAR(255) NOT NULL,
                tool_call_id VARCHAR(255) NOT NULL,
                tool_name VARCHAR(255) NOT NULL,
                pid INTEGER NOT NULL,
                log_path VARCHAR(255) NOT NULL,
                command_preview VARCHAR(200) NOT NULL,
                prompt VARCHAR(255) NOT NULL,
                kind VARCHAR(50) NOT NULL DEFAULT \'confirm\',
                status VARCHAR(255) NOT NULL DEFAULT \'pending\',
                answer BOOLEAN DEFAULT NULL,
                answer_text VARCHAR(255) DEFAULT NULL,
                schema TEXT DEFAULT NULL,
                emitted_at DATETIME DEFAULT NULL,
                answered_at DATETIME DEFAULT NULL,
                created_at DATETIME NOT NULL,
                updated_at DATETIME NOT NULL
            )',
        );
        $pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS UNIQ_TOOL_QUESTION_REQUEST_ID ON tool_question (request_id)');

        $schema = json_encode([
            'type' => 'string',
            'enum' => ['✅ Allow once', '📌 Always allow', '❌ Block'],
        ], \JSON_THROW_ON_ERROR);
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $pdo->prepare(
            'INSERT INTO tool_question (
                request_id, run_id, tool_call_id, tool_name, pid, log_path, command_preview,
                prompt, kind, status, answer, answer_text, schema, emitted_at, answered_at, created_at, updated_at
            ) VALUES (
                :request_id, :run_id, :tool_call_id, :tool_name, :pid, :log_path, :command_preview,
                :prompt, :kind, :status, NULL, NULL, :schema, NULL, NULL, :created_at, :updated_at
            )',
        );
        $stmt->execute([
            'request_id' => self::REQUEST_ID,
            'run_id' => $childRunId,
            'tool_call_id' => self::TOOL_CALL_ID,
            'tool_name' => 'write',
            'pid' => getmypid() ?: 1,
            'log_path' => '/tmp/sg-child-needs-input.log',
            'command_preview' => 'write ../outside.txt',
            'prompt' => self::PROMPT,
            'kind' => 'approval',
            'status' => 'pending',
            'schema' => $schema,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function childEvent(string $runId, int $seq, int $turn, string $type, array $payload, string $ts): array
    {
        return [
            'schema_version' => '1.0',
            'run_id' => $runId,
            'seq' => $seq,
            'turn_no' => $turn,
            'type' => $type,
            'payload' => $payload,
            'ts' => $ts,
        ];
    }
}
