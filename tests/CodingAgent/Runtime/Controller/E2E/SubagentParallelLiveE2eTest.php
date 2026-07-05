<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live controller E2E: parent calls subagent with parallel tasks array.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class SubagentParallelLiveE2eTest extends ControllerE2eTestCase
{
    private const CHILD_A = 'live-parallel-child-a';
    private const CHILD_B = 'live-parallel-child-b';
    private const TOKEN_A = 'PARALLEL_CHILD_A_OK';
    private const TOKEN_B = 'PARALLEL_CHILD_B_OK';

    protected function setUp(): void
    {
        parent::setUp();

        $agentsDir = $this->tempDir.'/.hatfield/agents';
        TestDirectoryIsolation::ensureDirectory($agentsDir, 0o777);

        $this->writeAgent($agentsDir.'/live-parallel-child-a.md', self::CHILD_A, self::TOKEN_A);
        $this->writeAgent($agentsDir.'/live-parallel-child-b.md', self::CHILD_B, self::TOKEN_B);
    }

    public function testParallelSubagentsReturnArtifactReport(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_parallel_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:subagent-parallel-v1] Agents "'.self::CHILD_A.'" and "'.self::CHILD_B.'" are defined in this project. '
                    .'Call tool subagent exactly once with JSON arguments {"tasks":[{"agent":"'.self::CHILD_A.'","task":"Reply with exactly '.self::TOKEN_A.' only. No tools."},{"agent":"'.self::CHILD_B.'","task":"Reply with exactly '.self::TOKEN_B.' only. No tools."}]}. '
                    .'Do not call any tool except subagent.',
            ],
        ]);

        $events = $this->collectEventsUntilToolCompleted('subagent', $this->liveLlmToolWaitTimeout());
        $byType = $this->indexByType($events);

        $this->assertStartRunAcked($events, $startCmdId);
        $this->assertArrayHasKey('tool_execution.completed', $byType, $this->collectDiagnostics($events));

        $started = $byType['tool_execution.started'][0] ?? null;
        $this->assertNotNull($started);
        $callId = (string) ($started['payload']['tool_call_id'] ?? '');
        $completed = $this->findCompletedPayloadForCallId($events, $callId);
        $this->assertNotNull($completed, $this->collectDiagnostics($events));
        $this->assertFalse($completed['is_error'] ?? true, $this->collectDiagnostics($events));

        $result = (string) ($completed['result'] ?? '');
        $this->assertStringContainsString('Parallel subagents completed', $result, $result);
        $this->assertStringContainsString(self::TOKEN_A, $result, $result);
        $this->assertStringContainsString(self::TOKEN_B, $result, $result);
        $this->assertGreaterThanOrEqual(2, preg_match_all('/Artifact: agent_[0-9a-f]{16}/', $result, $m), $result);

        $runStarted = $byType['run.started'][0] ?? null;
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $registryPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/artifacts/agents/registry.json';
        $this->assertFileExists($registryPath);
        $registryRaw = (string) file_get_contents($registryPath);
        $this->assertStringContainsString(self::CHILD_A, $registryRaw);
        $this->assertStringContainsString(self::CHILD_B, $registryRaw);
    }

    protected function tempDirPrefix(): string
    {
        return 'test-subagent-parallel';
    }

    /**
     * @return list<string>
     */
    protected function controllerExtraArgs(): array
    {
        return ['--tools=subagent'];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
agents:
    enabled: true
    max_agents: 8
    paths:
        - .hatfield/agents/live-parallel-child-a.md
        - .hatfield/agents/live-parallel-child-b.md
YAML;
    }

    /**
     * @return array<string, string>
     */
    protected function controllerSubprocessEnv(): array
    {
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '60'];
    }

    protected function liveLlmToolWaitTimeout(): float
    {
        // Warm llama-proxy replay: parent tool + two child runs typically <10s.
        return 25.0;
    }

    private function writeAgent(string $path, string $name, string $token): void
    {
        $content = <<<MD
---
name: {$name}
description: "Deterministic parallel child for live E2E"
tools:
  - read
mcp:
  mode: none
inheritProjectContext: false
inheritAgentsMd: false
foregroundAllowed: true
backgroundAllowed: false
parallelAllowed: true
disabled: false
---
Reply with exactly {$token} only. Do not call read or any other tool.
MD;

        if (false === file_put_contents($path, $content)) {
            throw new \RuntimeException('Failed to write agent: '.$path);
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>|null
     */
    private function findCompletedPayloadForCallId(array $events, string $toolCallId): ?array
    {
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'tool_execution.completed') {
                continue;
            }
            $payload = $event['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            if (($payload['tool_call_id'] ?? '') === $toolCallId) {
                return $payload;
            }
        }

        return null;
    }
}
