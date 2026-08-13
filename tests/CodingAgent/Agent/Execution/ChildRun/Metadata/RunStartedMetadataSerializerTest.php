<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun\Metadata;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Thesis: Symfony Serializer denormalizes stable nested RunStarted metadata;
 * SubagentRunMetadataReader exposes typed child launch fields without a Decoder.
 */
final class RunStartedMetadataSerializerTest extends TestCase
{
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->denormalizer = AttributeSerializerValidatorTestFactory::denormalizer();
    }

    public function testCanonicalSubagentMetadataDenormalizesTypedFields(): void
    {
        $dto = RunStartedMetadataDTO::tryFromRunEventPayload([
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
        ], $this->denormalizer);

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

    public function testCanonicalForkMetadataDenormalizesChildKind(): void
    {
        $dto = $this->denormalizer->denormalize([
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
        ], RunStartedMetadataDTO::class);

        $this->assertInstanceOf(RunStartedMetadataDTO::class, $dto);
        $this->assertTrue($dto->isAgentChild());
        $this->assertSame('fork', $dto->session->childKind);
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        $this->assertSame([], $dto->allowedExtensionsForChild());
    }

    public function testMissingExtensionsFailsClosedForChildAllowlist(): void
    {
        $dto = $this->denormalizer->denormalize([
            'session' => [
                'kind' => 'agent_child',
                'parent_run_id' => 'parent-1',
            ],
            'tools_scope' => [
                'allowed_tools' => ['read'],
            ],
        ], RunStartedMetadataDTO::class);

        $this->assertInstanceOf(RunStartedMetadataDTO::class, $dto);
        $this->assertTrue($dto->isAgentChild());
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        $this->assertSame([], $dto->allowedExtensionsForChild());
        $this->assertNull($dto->model);
        $this->assertNull($dto->contextWindow);
    }

    public function testMalformedEnvelopeReturnsNullAndParentDoesNotClassifyAsChild(): void
    {
        $this->assertNull(RunStartedMetadataDTO::tryFromRunEventPayload([], $this->denormalizer));
        $this->assertNull(RunStartedMetadataDTO::tryFromRunEventPayload(['payload' => 'not-array'], $this->denormalizer));
        $this->assertNull(RunStartedMetadataDTO::tryFromRunEventPayload(['payload' => ['metadata' => 'x']], $this->denormalizer));

        $parent = $this->denormalizer->denormalize([
            'session' => ['kind' => 'parent'],
            'model' => 'openai/gpt-5',
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $parent);
        $this->assertFalse($parent->isAgentChild());
        $this->assertNull($parent->allowedToolsForChild());
        $this->assertNull($parent->allowedExtensionsForChild());
    }

    public function testStrictMalformedNestedTypeFailsClosed(): void
    {
        // Scalar interactive must not soft-coerce; Serializer type mismatch fails closed.
        $dto = RunStartedMetadataDTO::tryFromRunEventPayload([
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'interactive' => 'false',
                    ],
                ],
            ],
        ], $this->denormalizer);

        $this->assertNull($dto);
    }

    public function testReaderUsesSerializerForCanonicalNestedEnvelope(): void
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

        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);
        $this->assertTrue($reader->isAgentChild($runId));
        $this->assertSame('parent-9', $reader->readParentRunId($runId));
        $this->assertSame(['bash'], $reader->readAllowedTools($runId));
        $this->assertSame([], $reader->readAllowedExtensions($runId));

        $typed = $reader->readRunStartedMetadata($runId);
        $this->assertNotNull($typed);
        $this->assertSame('fork', $typed->session->childKind);
        $this->assertSame('m', $typed->model);
    }

    public function testMissingRunStartedReturnsNullAndNotChild(): void
    {
        $reader = new SubagentRunMetadataReader(new InMemoryEventStore(), $this->denormalizer);
        $this->assertNull($reader->readRunStartedMetadata('missing'));
        $this->assertFalse($reader->isAgentChild('missing'));
        $this->assertNull($reader->readParentRunId('missing'));
        $this->assertNull($reader->readAllowedTools('missing'));
        $this->assertNull($reader->readAllowedExtensions('missing'));
    }

    public function testInteractiveLiteralBoolIsPreserved(): void
    {
        $false = $this->denormalizer->denormalize([
            'session' => ['kind' => 'agent_child', 'interactive' => false],
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $false);
        $this->assertFalse($false->session->interactive);

        $true = $this->denormalizer->denormalize([
            'session' => ['kind' => 'agent_child', 'interactive' => true],
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $true);
        $this->assertTrue($true->session->interactive);

        $absent = $this->denormalizer->denormalize([
            'session' => ['kind' => 'agent_child'],
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $absent);
        $this->assertNull($absent->session->interactive);
    }
}
