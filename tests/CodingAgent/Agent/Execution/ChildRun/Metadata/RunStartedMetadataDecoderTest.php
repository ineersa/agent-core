<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun\Metadata;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDecoder;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedSessionMetadataDTO;
use PHPUnit\Framework\TestCase;

/**
 * Thesis: one decoder turns canonical/historical run_started metadata into typed
 * child launch fields; parent/missing/malformed shapes do not false-classify as children.
 */
final class RunStartedMetadataDecoderTest extends TestCase
{
    public function testCanonicalSubagentMetadataDecodesTypedFields(): void
    {
        $decoder = new RunStartedMetadataDecoder();
        $dto = $decoder->fromRunEventPayload([
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'parent-1',
                        'agent_name' => 'scout',
                        'artifact_id' => 'agent_abc123',
                        'interactive' => false,
                    ],
                    'model' => 'deepseek/deepseek-v4-flash',
                    'reasoning' => 'medium',
                    'provider' => 'deepseek',
                    'context_window' => 128000,
                    'tools_scope' => [
                        'allowed_tools' => ['read', 'bash'],
                        'mcp' => ['mode' => 'none', 'tools' => []],
                    ],
                    'extensions' => ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
                ],
            ],
        ]);

        $this->assertNotNull($dto);
        $this->assertTrue($dto->isAgentChild());
        $this->assertNull($dto->session->childKind);
        $this->assertSame('parent-1', $dto->session->parentRunId);
        $this->assertSame('scout', $dto->session->agentName);
        $this->assertSame('agent_abc123', $dto->session->artifactId);
        $this->assertFalse($dto->session->interactive);
        $this->assertSame('deepseek/deepseek-v4-flash', $dto->model);
        $this->assertSame('medium', $dto->reasoning);
        $this->assertSame('deepseek', $dto->provider);
        $this->assertSame(128000, $dto->contextWindow);
        $this->assertSame(['read', 'bash'], $dto->allowedToolsForChild());
        $this->assertSame(
            ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
            $dto->allowedExtensionsForChild(),
        );
    }

    public function testCanonicalForkMetadataDecodesChildKind(): void
    {
        $decoder = new RunStartedMetadataDecoder();
        $dto = $decoder->fromMetadataArray([
            'session' => [
                'kind' => 'agent_child',
                'child_kind' => 'fork',
                'parent_run_id' => 'parent-run',
                'agent_name' => 'fork',
                'artifact_id' => 'agent_fork1',
                'interactive' => true,
            ],
            'model' => 'openai/gpt-5',
            'tools_scope' => [
                'allowed_tools' => ['read'],
                'mcp' => [],
            ],
            'extensions' => [],
        ]);

        $this->assertTrue($dto->isAgentChild());
        $this->assertTrue($dto->session->isForkChild());
        $this->assertSame(RunStartedSessionMetadataDTO::CHILD_KIND_FORK, $dto->session->childKind);
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        $this->assertSame([], $dto->allowedExtensionsForChild());
    }

    public function testMinimalHistoricalChildStillClassifiesAndFailsClosedOnMissingExtensions(): void
    {
        $decoder = new RunStartedMetadataDecoder();
        $dto = $decoder->fromMetadataArray([
            'session' => [
                'kind' => 'agent_child',
                'parent_run_id' => 'parent-1',
            ],
            'tools_scope' => [
                'allowed_tools' => ['read'],
            ],
        ]);

        $this->assertTrue($dto->isAgentChild());
        $this->assertSame('parent-1', $dto->session->parentRunId);
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        // Pre-selection metadata: extensions key absent → empty allowlist.
        $this->assertSame([], $dto->allowedExtensionsForChild());
        $this->assertNull($dto->model);
        $this->assertNull($dto->contextWindow);
    }

    public function testParentAndMalformedShapesDoNotFalseClassify(): void
    {
        $decoder = new RunStartedMetadataDecoder();

        $this->assertNull($decoder->fromRunEventPayload([]));
        $this->assertNull($decoder->fromRunEventPayload(['payload' => 'not-array']));
        $this->assertNull($decoder->fromRunEventPayload(['payload' => ['metadata' => 'x']]));

        $parent = $decoder->fromMetadataArray([
            'session' => ['kind' => 'parent'],
            'model' => 'openai/gpt-5',
        ]);
        $this->assertFalse($parent->isAgentChild());
        $this->assertNull($parent->allowedToolsForChild());
        $this->assertNull($parent->allowedExtensionsForChild());

        $missingKind = $decoder->fromMetadataArray(['session' => ['parent_run_id' => 'p']]);
        $this->assertFalse($missingKind->isAgentChild());
    }

    public function testReaderUsesDecoderForCanonicalNestedEnvelope(): void
    {
        $runId = 'child-run';
        $store = new InMemoryEventStore();
        $store->append(new RunEvent(
            runId: $runId,
            seq: 1,
            turnNo: 0,
            type: RunEventTypeEnum::RunStarted->value,
            payload: [
                'payload' => [
                    'metadata' => [
                        'session' => [
                            'kind' => 'agent_child',
                            'child_kind' => 'fork',
                            'parent_run_id' => 'parent-9',
                            'artifact_id' => 'agent_f9',
                            'interactive' => true,
                        ],
                        'model' => 'm',
                        'tools_scope' => [
                            'allowed_tools' => ['bash'],
                            'mcp' => [],
                        ],
                        'extensions' => [],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $reader = new SubagentRunMetadataReader($store, new RunStartedMetadataDecoder());
        $this->assertTrue($reader->isAgentChild($runId));
        $this->assertSame('parent-9', $reader->readParentRunId($runId));
        $this->assertSame(['bash'], $reader->readAllowedTools($runId));
        $this->assertSame([], $reader->readAllowedExtensions($runId));

        $typed = $reader->readRunStartedMetadata($runId);
        $this->assertNotNull($typed);
        $this->assertSame(RunStartedSessionMetadataDTO::CHILD_KIND_FORK, $typed->session->childKind);
        $this->assertSame('m', $typed->model);
    }

    public function testMissingRunStartedReturnsNullAndNotChild(): void
    {
        $reader = new SubagentRunMetadataReader(new InMemoryEventStore());
        $this->assertNull($reader->readRunStartedMetadata('missing'));
        $this->assertFalse($reader->isAgentChild('missing'));
        $this->assertNull($reader->readParentRunId('missing'));
        $this->assertNull($reader->readAllowedTools('missing'));
        $this->assertNull($reader->readAllowedExtensions('missing'));
    }
}
