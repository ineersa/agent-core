<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Schema;

use Ineersa\AgentCore\Domain\Command\CoreCommandKind;
use Ineersa\AgentCore\Domain\Message\ApplyCommand;
use Ineersa\AgentCore\Domain\Message\ExecuteLlmStep;
use Ineersa\AgentCore\Domain\Message\LlmStepResult;
use Ineersa\AgentCore\Domain\Message\StartRun;
use Ineersa\AgentCore\Schema\CommandPayloadNormalizer;
use PHPUnit\Framework\TestCase;

final class CommandPayloadNormalizerGoldenTest extends TestCase
{
    private const string RUN_ID = 'd2f2f4ab-8d80-4c6f-84bc-96db31207c72';

    private CommandPayloadNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new CommandPayloadNormalizer();
    }

    public function testStartRunMatchesReferenceSchemaFixture(): void
    {
        $payload = $this->normalizer->normalizeStartRun(new StartRun(
            runId: self::RUN_ID,
            turnNo: 0,
            stepId: 'start-1',
            attempt: 1,
            idempotencyKey: 'start:d2f2f4ab',
            payload: [
                'user_id' => '9fe6dfab-5e88-4c8f-89fa-72fe8dd57c08',
                'initial_message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Analyze repo']],
                    'timestamp' => 1770000000,
                ],
            ],
        ));

        self::assertSame($this->fixture('commands/start-run.json'), $payload);
    }

    public function testApplySteerCommandMatchesReferenceSchemaFixture(): void
    {
        $payload = $this->normalizer->normalizeApplyCommand(new ApplyCommand(
            runId: self::RUN_ID,
            turnNo: 3,
            stepId: 'turn-3-command-1',
            attempt: 1,
            idempotencyKey: 'steer:42',
            kind: CoreCommandKind::Steer,
            payload: [
                'message' => [
                    'role' => 'user',
                    'content' => [['type' => 'text', 'text' => 'Stop and summarize first']],
                    'timestamp' => 1770001111,
                ],
            ],
        ));

        self::assertSame($this->fixture('commands/apply-steer-command.json'), $payload);
    }

    public function testApplyExtensionCommandMatchesReferenceSchemaFixture(): void
    {
        $payload = $this->normalizer->normalizeApplyCommand(new ApplyCommand(
            runId: self::RUN_ID,
            turnNo: 3,
            stepId: 'turn-3-command-2',
            attempt: 1,
            idempotencyKey: 'ext-compaction-3',
            kind: 'ext:compaction:compact',
            payload: [
                'custom_instructions' => 'Summarize implementation decisions and open risks',
            ],
            options: ['cancel_safe' => false],
        ));

        self::assertSame($this->fixture('commands/apply-extension-command.json'), $payload);
    }

    public function testApplyCancelSafeExtensionCommandMatchesReferenceSchemaFixture(): void
    {
        $payload = $this->normalizer->normalizeApplyCommand(new ApplyCommand(
            runId: self::RUN_ID,
            turnNo: 3,
            stepId: 'turn-3-command-3',
            attempt: 1,
            idempotencyKey: 'ext-cleanup-1',
            kind: 'ext:cleanup:flush',
            payload: ['reason' => 'run_cancelled'],
            options: ['cancel_safe' => true],
        ));

        self::assertSame($this->fixture('commands/apply-extension-cancel-safe-command.json'), $payload);
    }

    public function testExecuteLlmStepMatchesReferenceSchemaFixture(): void
    {
        $payload = $this->normalizer->normalizeExecuteLlmStep(new ExecuteLlmStep(
            runId: self::RUN_ID,
            turnNo: 3,
            stepId: 'turn-3-llm-1',
            attempt: 1,
            idempotencyKey: 'llm:turn-3-1',
            contextRef: 'hot:run:d2f2f4ab',
            toolsRef: 'toolset:tenant:acme:turn:3',
        ));

        self::assertSame($this->fixture('execution/execute-llm-step.json'), $payload);
    }

    public function testLlmStepResultMatchesReferenceSchemaFixture(): void
    {
        $payload = $this->normalizer->normalizeLlmStepResult(new LlmStepResult(
            runId: self::RUN_ID,
            turnNo: 3,
            stepId: 'turn-3-llm-1',
            attempt: 1,
            idempotencyKey: 'llm:turn-3-1',
            assistantMessage: [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_tc_1',
                    'name' => 'web_search',
                    'arguments' => ['query' => 'symfony workflow'],
                ]],
                'stop_reason' => 'tool_call',
                'timestamp' => 1770001234,
            ],
            usage: [
                'input_tokens' => 1234,
                'output_tokens' => 345,
                'total_tokens' => 1579,
            ],
            stopReason: 'tool_call',
            error: null,
        ));

        self::assertSame($this->fixture('execution/llm-step-result.json'), $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $relativePath): array
    {
        $fullPath = __DIR__.'/../Fixtures/Schema/'.$relativePath;
        $contents = file_get_contents($fullPath);
        self::assertNotFalse($contents, 'Failed to read fixture: '.$relativePath);

        $decoded = json_decode($contents, true);
        self::assertIsArray($decoded, 'Fixture JSON must decode to an object: '.$relativePath);

        return $decoded;
    }
}
