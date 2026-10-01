<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Tests\Session\History\InMemoryHistoryProjectionStore;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: ordinary RunStarted metadata reads come from the ready shared history
 * projection and never scan the event archive.
 */
final class RunStartedMetadataReaderCacheTest extends TestCase
{
    public function testRepeatedSuccessfulReadsUseSharedProjectionOnly(): void
    {
        $store = new InMemoryHistoryProjectionStore();
        $store->initializeFromEvents('child-1', [$this->runStarted('child-1', child: true)]);
        $reader = new RunStartedMetadataReader($store);

        $this->assertSame(['bash'], $reader->readAllowedTools('child-1'));
        $this->assertSame([], $reader->readAllowedExtensions('child-1'));
        $this->assertNotNull($reader->readRunStartedMetadata('child-1'));
        $this->assertSame('deepseek/deepseek-v4-flash', $reader->readRunStartedMetadata('child-1')?->model);
        $this->assertSame(4, $store->getCalls);
    }

    public function testValidParentAndChildProjectionsAreIndependent(): void
    {
        $store = new InMemoryHistoryProjectionStore();
        $store->initializeFromEvents('parent-1', [$this->runStarted('parent-1', child: false)]);
        $store->initializeFromEvents('child-2', [$this->runStarted('child-2', child: true, parentRunId: 'parent-1')]);
        $reader = new RunStartedMetadataReader($store);

        $this->assertNull($reader->readAllowedTools('parent-1'));
        $this->assertSame(['bash'], $reader->readAllowedTools('child-2'));
        $this->assertNull($reader->readAllowedTools('parent-1'));
        $this->assertSame(['bash'], $reader->readAllowedTools('child-2'));
    }

    public function testMissingProjectionFailsClosedUntilInitialized(): void
    {
        $store = new InMemoryHistoryProjectionStore();
        $reader = new RunStartedMetadataReader($store);

        try {
            $reader->readRunStartedMetadata('late-child');
            $this->fail('Expected missing projection to fail closed');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('History projection missing', $exception->getMessage());
        }

        $store->initializeFromEvents('late-child', [$this->runStarted('late-child', child: true)]);
        $this->assertSame(['bash'], $reader->readAllowedTools('late-child'));
    }

    /**
     * @param non-empty-string $runId
     */
    private function runStarted(string $runId, bool $child, string $parentRunId = 'parent-1'): RunEvent
    {
        if ($child) {
            $payload = [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'You are a scout.',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'parent_run_id' => $parentRunId,
                            'agent_name' => 'scout',
                            'artifact_id' => 'agent_abc123',
                            'interactive' => false,
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => [
                                'mode' => 'none',
                                'tools' => [],
                            ],
                        ],
                        'extensions' => [],
                    ],
                ],
            ];
        } else {
            $payload = [
                'step_id' => 'start-1',
                'payload' => [
                    'system_prompt' => 'You are hatfield.',
                    'messages' => [],
                    'metadata' => [
                        'session' => [
                            'kind' => 'main',
                        ],
                        'model' => 'deepseek/deepseek-v4-flash',
                        'reasoning' => 'medium',
                        'tools_scope' => [
                            'allowed_tools' => ['bash', 'read'],
                        ],
                    ],
                ],
            ];
        }

        return new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: $payload,
            createdAt: new \DateTimeImmutable(),
        );
    }
}
