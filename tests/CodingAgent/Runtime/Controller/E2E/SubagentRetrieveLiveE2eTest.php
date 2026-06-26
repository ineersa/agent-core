<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Runtime\Controller\E2E;

use Ineersa\CodingAgent\Tests\Support\TestDirectoryIsolation;
use PHPUnit\Framework\Attributes\Group;

/**
 * Live controller E2E: parent launches foreground subagent, receives Artifact id
 * in the tool result, then calls agent_retrieve on the same parent run.
 *
 * @group llm-real
 */
#[Group('llm-real')]
final class SubagentRetrieveLiveE2eTest extends ControllerE2eTestCase
{
    private const CHILD_AGENT = 'live-retriever-child';
    private const HANDOFF_TOKEN = 'CHILD_HANDOFF_OK';

    protected function setUp(): void
    {
        parent::setUp();

        $hatfieldAgentsDir = $this->tempDir.'/.hatfield/agents';
        TestDirectoryIsolation::ensureDirectory($hatfieldAgentsDir, 0o777);

        $agentPath = $hatfieldAgentsDir.'/live-retriever-child.md';
        $content = <<<'MD'
---
name: live-retriever-child
description: "Deterministic child for subagent retrieve live E2E"
tools:
  - read
mcp:
  mode: none
inheritProjectContext: false
inheritAgentsMd: false
foregroundAllowed: true
backgroundAllowed: false
parallelAllowed: false
disabled: false
---
Reply with exactly CHILD_HANDOFF_OK only. Do not call read or any other tool.
MD;

        if (false === file_put_contents($agentPath, $content)) {
            throw new \RuntimeException('Failed to write agent definition: '.$agentPath);
        }
    }

    public function testSubagentThenAgentRetrieveChain(): void
    {
        $this->spawnController();
        $this->waitForEvent('runtime.ready', $this->liveControllerReadyTimeout());

        $startCmdId = 'cmd_subagent_'.uniqid();
        $this->writeCommand([
            'v' => 1,
            'id' => $startCmdId,
            'type' => 'start_run',
            'payload' => [
                'prompt' => '[llm-real:subagent-retrieve-chain-v1] The agent "'.self::CHILD_AGENT.'" is defined in this project. '
                    .'Call tool subagent exactly once with JSON arguments {"agent":"'.self::CHILD_AGENT.'","task":"Reply with exactly '.self::HANDOFF_TOKEN.' only. No tools."}. '
                    .'Do not call read, write, grep, or any tool except subagent.',
            ],
        ]);

        $subagentEvents = $this->collectEventsUntilToolCompleted('subagent', $this->liveLlmToolWaitTimeout());
        $subagentByType = $this->indexByType($subagentEvents);

        $this->assertStartRunAcked($subagentEvents, $startCmdId);
        $this->assertArrayHasKey('run.started', $subagentByType,
            'Expected run.started. '.$this->collectDiagnostics($subagentEvents));

        $runStarted = $subagentByType['run.started'][0];
        $this->runId = (string) ($runStarted['runId'] ?? $runStarted['payload']['runId'] ?? '');
        $this->assertNotEmpty($this->runId);

        $this->assertArrayHasKey('tool_execution.started', $subagentByType,
            'subagent tool must start. '.$this->collectDiagnostics($subagentEvents));
        $this->assertSame(
            'subagent',
            $subagentByType['tool_execution.started'][0]['payload']['tool_name'] ?? null,
            'Expected subagent tool_execution.started. '.$this->collectDiagnostics($subagentEvents),
        );

        $subagentCallId = (string) ($subagentByType['tool_execution.started'][0]['payload']['tool_call_id'] ?? '');
        $this->assertNotEmpty($subagentCallId);

        if (!isset($subagentByType['tool_execution.completed'])) {
            $failMsg = 'subagent tool must complete. '.$this->collectDiagnostics($subagentEvents);
            if (isset($subagentByType['tool_execution.failed'])) {
                $failMsg .= "\nFailed payload: ".json_encode($subagentByType['tool_execution.failed'][0]['payload'] ?? [], \JSON_PRETTY_PRINT);
            }
            $this->fail($failMsg);
        }

        $subagentCompleted = $this->findCompletedPayloadForCallId($subagentEvents, $subagentCallId);
        $this->assertNotNull($subagentCompleted, 'Missing matching tool_execution.completed for subagent. '
            .$this->collectDiagnostics($subagentEvents));

        $this->assertFalse($subagentCompleted['is_error'] ?? true,
            'subagent tool must not complete with is_error=true. '.$this->collectDiagnostics($subagentEvents));

        $subagentResult = (string) ($subagentCompleted['result'] ?? '');
        $this->assertNotSame('', $subagentResult, 'subagent completed payload must include result. '
            .$this->collectDiagnostics($subagentEvents));

        $this->assertStringContainsString(self::HANDOFF_TOKEN, $subagentResult,
            'subagent result must include child handoff token. '.$this->collectDiagnostics($subagentEvents));
        $this->assertStringContainsString('Subagent '.self::CHILD_AGENT.' completed.', $subagentResult,
            'subagent result must include success banner. '.$this->collectDiagnostics($subagentEvents));

        if (1 !== preg_match('/Artifact: (agent_[0-9a-f]{16})/', $subagentResult, $matches)) {
            $this->fail('subagent result must include Artifact: agent_<16 hex>. Result: '.$subagentResult."\n"
                .$this->collectDiagnostics($subagentEvents));
        }
        $artifactId = $matches[1];

        $registryPath = $this->tempDir.'/.hatfield/sessions/'.$this->runId.'/artifacts/agents/registry.json';
        $this->assertFileExists($registryPath,
            'Parent artifact registry must exist after subagent. '.$this->collectDiagnostics($subagentEvents));
        $registryRaw = (string) file_get_contents($registryPath);
        $this->assertStringContainsString($artifactId, $registryRaw,
            'Registry must reference parsed artifact id. '.$this->collectDiagnostics($subagentEvents));

        $this->assertNoToolFailedForName($subagentEvents, 'subagent');

        // follow_up is sent immediately after subagent tool completion; the controller
        // interleaves it with the post-subagent model turn so agent_retrieve can run
        // before the parent run reaches a terminal state.
        $followUpCmdId = 'cmd_retrieve_'.uniqid();
        $retrieveArgs = json_encode([
            'artifact_id' => $artifactId,
            'mode' => 'handoff',
            'limit' => 5,
        ], \JSON_THROW_ON_ERROR);

        $this->writeCommand([
            'v' => 1,
            'id' => $followUpCmdId,
            'type' => 'follow_up',
            'runId' => $this->runId,
            'payload' => [
                'text' => '[llm-real:subagent-retrieve-chain-v1-step2] Call tool agent_retrieve exactly once with JSON arguments '
                    .$retrieveArgs.'. Do not call subagent or any other tool.',
            ],
        ]);

        $retrieveEvents = $this->collectEventsUntilToolCompleted('agent_retrieve', $this->liveLlmToolWaitTimeout());
        $retrieveByType = $this->indexByType($retrieveEvents);

        $this->assertTrue($this->foundAck($retrieveEvents, $followUpCmdId),
            'follow_up: expected command.ack. '.$this->collectDiagnostics($retrieveEvents));

        $this->assertArrayHasKey('tool_execution.started', $retrieveByType,
            'agent_retrieve must start. '.$this->collectDiagnostics($retrieveEvents));

        $retrieveStarted = null;
        foreach ($retrieveByType['tool_execution.started'] as $started) {
            if ('agent_retrieve' === ($started['payload']['tool_name'] ?? null)) {
                $retrieveStarted = $started;
                break;
            }
        }
        $this->assertNotNull($retrieveStarted, 'Expected agent_retrieve tool_execution.started. '
            .$this->collectDiagnostics($retrieveEvents));

        $retrieveCallId = (string) ($retrieveStarted['payload']['tool_call_id'] ?? '');
        $this->assertNotEmpty($retrieveCallId);

        $retrieveCompleted = $this->findCompletedPayloadForCallId($retrieveEvents, $retrieveCallId);
        $this->assertNotNull($retrieveCompleted, 'Missing matching tool_execution.completed for agent_retrieve. '
            .$this->collectDiagnostics($retrieveEvents));

        $this->assertFalse($retrieveCompleted['is_error'] ?? true,
            'agent_retrieve must not complete with is_error=true. '.$this->collectDiagnostics($retrieveEvents));

        $retrieveResult = (string) ($retrieveCompleted['result'] ?? '');
        $this->assertStringContainsString(self::HANDOFF_TOKEN, $retrieveResult,
            'agent_retrieve handoff must include child token. '.$this->collectDiagnostics($retrieveEvents));
        $this->assertStringNotContainsString($this->tempDir, $retrieveResult,
            'agent_retrieve must not expose absolute temp paths. '.$this->collectDiagnostics($retrieveEvents));

        $this->assertNoToolFailedForName($retrieveEvents, 'agent_retrieve');

        $sessionDir = $this->tempDir.'/.hatfield/sessions/'.$this->runId;
        $this->assertSessionArtifactsExist($sessionDir, array_merge($subagentEvents, $retrieveEvents));
    }

    protected function tempDirPrefix(): string
    {
        return 'test-subagent-retrieve';
    }

    /**
     * @return list<string>
     */
    protected function controllerExtraArgs(): array
    {
        return ['--tools=subagent,agent_retrieve'];
    }

    protected function extraSettingsYaml(): string
    {
        return <<<'YAML'
agents:
    enabled: true
    paths:
        - .hatfield/agents/live-retriever-child.md
YAML;
    }

    /**
     * @return array<string, string>
     */
    protected function controllerSubprocessEnv(): array
    {
        return ['HATFIELD_TEST_LLM_HTTP_TIMEOUT' => '120'];
    }

    protected function liveLlmToolWaitTimeout(): float
    {
        // Parent LLM tool call + child LLM run + subagent poll.
        return 60.0;
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

    /**
     * @param list<array<string, mixed>> $events
     */
    private function assertNoToolFailedForName(array $events, string $toolName): void
    {
        foreach ($events as $event) {
            if (($event['type'] ?? '') !== 'tool_execution.failed') {
                continue;
            }
            $payload = $event['payload'] ?? [];
            if (!\is_array($payload)) {
                continue;
            }
            if ($toolName === ($payload['tool_name'] ?? null)) {
                $this->fail(
                    $toolName.' tool_execution.failed: '
                    .json_encode($payload, \JSON_PRETTY_PRINT)."\n"
                    .$this->collectDiagnostics($events),
                );
            }
        }
    }
}
