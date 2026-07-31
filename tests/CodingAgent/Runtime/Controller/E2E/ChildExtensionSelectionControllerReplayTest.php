<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use PHPUnit\Framework\Attributes\Group;

/**
 * Controller-replay E2E: parent launches a subagent through the real controller +
 * Messenger child-worker path; child RunStarted metadata must carry the effective
 * extension allowlist (always_on only when frontmatter omits extensions).
 *
 * Thesis: durable child extension selection survives async worker execution.
 */
#[Group('controller-replay')]
final class ChildExtensionSelectionControllerReplayTest extends ControllerReplayE2eTestCase
{
    private const CHILD_AGENT = 'ext-select-child';
    private const CHILD_TOKEN = 'EXT_SELECT_OK';

    protected function setUp(): void
    {
        parent::setUp();

        $agentsDir = $this->tempDir.'/.hatfield/agents';
        if (!is_dir($agentsDir) && !mkdir($agentsDir, 0o755, true) && !is_dir($agentsDir)) {
            $this->fail('Failed to create agents dir');
        }

        // No optional extensions — always_on only.
        $agentName = self::CHILD_AGENT;
        $token = self::CHILD_TOKEN;
        file_put_contents($agentsDir.'/'.$agentName.'.md', <<<MD
---
name: {$agentName}
description: "Child for extension allowlist controller replay"
tools:
  - read
---

Reply with exactly {$token} and nothing else.
MD);
    }

    public function testSubagentChildRunStartedMetadataContainsAlwaysOnOnly(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_ext_select_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => 'Call tool subagent exactly once with agent '.self::CHILD_AGENT
                    .' and task "Reply with exactly '.self::CHILD_TOKEN.' only. No tools."'
                    .' Do not call any other tool.',
            ],
        ]);

        $events = $this->collectEventsUntilToolCompleted('subagent', $this->liveLlmToolWaitTimeout());
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('run.started', $byType, $this->collectDiagnostics($events));
        $this->assertArrayNotHasKey('tool_execution.failed', $byType, $this->collectDiagnostics($events));

        $parentRunId = (string) ($byType['run.started'][0]['runId']
            ?? $byType['run.started'][0]['payload']['runId']
            ?? '');
        $this->assertNotSame('', $parentRunId);

        $childRunId = $this->findChildRunId($parentRunId);
        $this->assertNotNull($childRunId, 'Expected a child run directory under the parent session. '.$this->collectDiagnostics($events));

        $metadata = $this->readChildRunStartedExtensions($childRunId);
        $this->assertSame(
            ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
            $metadata,
            'Child effective extensions must be always_on only (SafeGuard). Diagnostics: '.$this->collectDiagnostics($events),
        );
    }

    protected function controllerExtraArgs(): array
    {
        return ['--tools=subagent'];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
agents:
    enabled: true
    paths:
        - .hatfield/agents/ext-select-child.md
    extensions:
        always_on:
            - Ineersa\CodingAgent\Extension\Builtin\SafeGuard\SafeGuardExtension
YAML;
    }

    protected function tempDirPrefix(): string
    {
        return 'test-child-ext-select-replay';
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function replayFixtures(): array
    {
        $parentSubagent = [
            '$schema' => 'Synthetic controller replay — parent subagent tool call',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-07-30T00:00:00+00:00',
            'recording_source' => 'manual',
            'input' => ['messages' => [['role' => 'user', 'content' => 'subagent']]],
            'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
            'stop_reason' => 'tool_call',
            'expected_text' => '',
            'deltas' => [
                ['type' => 'tool_call_start', 'id' => 'call_subagent_ext_1', 'name' => 'subagent'],
                ['type' => 'tool_input_delta', 'id' => 'call_subagent_ext_1', 'name' => 'subagent', 'partial_json' => '{"ag'],
                ['type' => 'tool_input_delta', 'id' => 'call_subagent_ext_1', 'name' => 'subagent', 'partial_json' => 'ent":'],
                ['type' => 'tool_input_delta', 'id' => 'call_subagent_ext_1', 'name' => 'subagent', 'partial_json' => '"'.self::CHILD_AGENT.'"'],
                ['type' => 'tool_input_delta', 'id' => 'call_subagent_ext_1', 'name' => 'subagent', 'partial_json' => ',"ta'],
                ['type' => 'tool_input_delta', 'id' => 'call_subagent_ext_1', 'name' => 'subagent', 'partial_json' => 'sk":"'],
                ['type' => 'tool_input_delta', 'id' => 'call_subagent_ext_1', 'name' => 'subagent', 'partial_json' => 'Reply with exactly '.self::CHILD_TOKEN.' only. No tools."}'],
            ],
        ];

        $childText = [
            '$schema' => 'Synthetic controller replay — child final text',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-07-30T00:00:00+00:00',
            'recording_source' => 'manual',
            'input' => ['messages' => [['role' => 'user', 'content' => 'child']]],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 5, 'total_tokens' => 10],
            'stop_reason' => 'stop',
            'expected_text' => self::CHILD_TOKEN,
            'deltas' => [
                ['type' => 'text', 'content' => self::CHILD_TOKEN],
            ],
        ];

        // Parent may continue after tool result with a short final answer.
        $parentFinal = [
            '$schema' => 'Synthetic controller replay — parent final after subagent',
            'model' => 'llama_cpp_test/test',
            'provider_id' => 'llama_cpp_test',
            'reasoning' => 'off',
            'recorded_at' => '2026-07-30T00:00:00+00:00',
            'recording_source' => 'manual',
            'input' => ['messages' => [['role' => 'user', 'content' => 'final']]],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 4, 'total_tokens' => 9],
            'stop_reason' => 'stop',
            'expected_text' => 'done',
            'deltas' => [
                ['type' => 'text', 'content' => 'done'],
            ],
        ];

        return [$parentSubagent, $childText, $parentFinal];
    }

    private function findChildRunId(string $parentRunId): ?string
    {
        $sessionsRoot = $this->tempDir.'/.hatfield/sessions';
        if (!is_dir($sessionsRoot)) {
            return null;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sessionsRoot, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || 'events.jsonl' !== $file->getFilename()) {
                continue;
            }

            $runDir = $file->getPath();
            $runId = basename($runDir);
            if ($runId === $parentRunId) {
                continue;
            }

            $extensions = $this->readChildRunStartedExtensions($runId);
            if (null !== $extensions) {
                return $runId;
            }
        }

        return null;
    }

    /**
     * @return list<string>|null
     */
    private function readChildRunStartedExtensions(string $runId): ?array
    {
        $path = $this->tempDir.'/.hatfield/sessions/'.$runId.'/events.jsonl';
        if (!is_file($path)) {
            // Nested child artifact layout: search.
            $found = null;
            $sessionsRoot = $this->tempDir.'/.hatfield/sessions';
            if (is_dir($sessionsRoot)) {
                $iterator = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($sessionsRoot, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($iterator as $file) {
                    if ($file->isFile() && 'events.jsonl' === $file->getFilename() && str_contains($file->getPath(), $runId)) {
                        $found = $file->getPathname();
                        break;
                    }
                }
            }
            if (null === $found) {
                return null;
            }
            $path = $found;
        }

        $lines = file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        if (false === $lines) {
            return null;
        }

        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if (!\is_array($event)) {
                continue;
            }
            $type = $event['type'] ?? null;
            if ('run.started' !== $type && 'RunStarted' !== $type && 'run_started' !== $type) {
                // Canonical domain type string in events.jsonl may be the enum value.
                if (!\is_string($type) || !str_contains(strtolower($type), 'started')) {
                    continue;
                }
            }

            $payload = $event['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }

            // Domain events.jsonl shape: payload.payload.metadata OR payload.metadata
            $inner = $payload['payload'] ?? $payload;
            if (!\is_array($inner)) {
                continue;
            }
            $metadata = $inner['metadata'] ?? null;
            if (!\is_array($metadata)) {
                continue;
            }
            $session = $metadata['session'] ?? null;
            if (!\is_array($session) || 'agent_child' !== ($session['kind'] ?? null)) {
                continue;
            }

            $extensions = $metadata['extensions'] ?? null;
            if (!\is_array($extensions)) {
                return [];
            }

            $classes = [];
            foreach ($extensions as $item) {
                if (\is_string($item) && '' !== trim($item)) {
                    $classes[] = trim($item);
                }
            }

            return $classes;
        }

        return null;
    }
}
