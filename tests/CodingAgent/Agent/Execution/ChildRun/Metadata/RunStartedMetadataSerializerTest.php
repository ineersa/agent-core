<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun\Metadata;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\SubagentRunMetadataReader;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedEventPayloadDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Thesis: Symfony Serializer denormalizes the full nested RunStarted envelope;
 * SubagentRunMetadataReader exposes typed child launch fields without a Decoder/static mapper.
 */
final class RunStartedMetadataSerializerTest extends TestCase
{
    private DenormalizerInterface $denormalizer;

    protected function setUp(): void
    {
        $this->denormalizer = AttributeSerializerValidatorTestFactory::denormalizer();
    }

    public function testCanonicalSubagentEnvelopeDenormalizesTypedGraph(): void
    {
        $envelope = $this->denormalizer->denormalize([
            'step_id' => 'step-1',
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
        ], RunStartedEventPayloadDTO::class);

        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $envelope);
        $dto = $envelope->payload->metadata;
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

    public function testCanonicalForkEnvelopeDenormalizesChildKind(): void
    {
        $envelope = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
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
                ],
            ],
        ], RunStartedEventPayloadDTO::class);

        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $envelope);
        $dto = $envelope->payload->metadata;
        $this->assertTrue($dto->isAgentChild());
        $this->assertSame('fork', $dto->session->childKind);
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        $this->assertSame([], $dto->allowedExtensionsForChild());
    }

    public function testMissingExtensionsFailsClosedForChildAllowlist(): void
    {
        $envelope = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'parent-1',
                    ],
                    'tools_scope' => [
                        'allowed_tools' => ['read'],
                    ],
                ],
            ],
        ], RunStartedEventPayloadDTO::class);

        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $envelope);
        $dto = $envelope->payload->metadata;
        $this->assertTrue($dto->isAgentChild());
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        $this->assertSame([], $dto->allowedExtensionsForChild());
        $this->assertNull($dto->model);
        $this->assertNull($dto->contextWindow);
    }

    public function testMissingRequiredEnvelopeFailsStrictly(): void
    {
        $this->expectException(SerializerExceptionInterface::class);
        $this->denormalizer->denormalize([], RunStartedEventPayloadDTO::class);
    }

    public function testMalformedNestedMetadataFailsStrictly(): void
    {
        $this->expectException(SerializerExceptionInterface::class);
        $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => 'x',
            ],
        ], RunStartedEventPayloadDTO::class);
    }

    public function testStrictMalformedNestedTypeFailsClosedAtReaderBoundary(): void
    {
        // Scalar interactive must not soft-coerce; boundary catch returns null.
        $runId = 'bad-interactive';
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
                            'interactive' => 'false',
                        ],
                    ],
                ],
            ],
            createdAt: new \DateTimeImmutable(),
        ));

        $reader = new SubagentRunMetadataReader($store, $this->denormalizer);
        $this->assertNull($reader->readRunStartedMetadata($runId));
        $this->assertFalse($reader->isAgentChild($runId));
    }

    public function testParentDoesNotClassifyAsChild(): void
    {
        $envelope = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => ['kind' => 'parent'],
                    'model' => 'openai/gpt-5',
                ],
            ],
        ], RunStartedEventPayloadDTO::class);

        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $envelope);
        $parent = $envelope->payload->metadata;
        $this->assertFalse($parent->isAgentChild());
        $this->assertNull($parent->allowedToolsForChild());
        $this->assertNull($parent->allowedExtensionsForChild());
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
            'payload' => [
                'metadata' => [
                    'session' => ['kind' => 'agent_child', 'interactive' => false],
                ],
            ],
        ], RunStartedEventPayloadDTO::class);
        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $false);
        $this->assertFalse($false->payload->metadata->session->interactive);

        $true = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => ['kind' => 'agent_child', 'interactive' => true],
                ],
            ],
        ], RunStartedEventPayloadDTO::class);
        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $true);
        $this->assertTrue($true->payload->metadata->session->interactive);

        $absent = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => ['kind' => 'agent_child'],
                ],
            ],
        ], RunStartedEventPayloadDTO::class);
        $this->assertInstanceOf(RunStartedEventPayloadDTO::class, $absent);
        $this->assertNull($absent->payload->metadata->session->interactive);
    }
}
