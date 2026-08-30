<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Agent\Execution\ChildRun\Metadata;

use Ineersa\AgentCore\Domain\Event\RunEvent;
use Ineersa\AgentCore\Domain\Event\RunEventTypeEnum;
use Ineersa\AgentCore\Tests\Support\AttributeSerializerValidatorTestFactory;
use Ineersa\AgentCore\Tests\Support\InMemoryEventStore;
use Ineersa\CodingAgent\Agent\Execution\RunStartedMetadataReader;
use Ineersa\CodingAgent\Extension\ChildRun\Metadata\RunStartedMetadataDTO;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Serializer\Exception\ExceptionInterface as SerializerExceptionInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;

/**
 * Thesis: Symfony Serializer denormalizes root RunEvent.payload into RunStartedMetadataDTO
 * via SerializedPath; RunStartedMetadataReader exposes typed child launch fields without wrappers.
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
        $dto = $this->denormalizer->denormalize([
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
                    'context_window' => 128000,
                    'tools_scope' => [
                        'allowed_tools' => ['read', 'bash'],
                        'mcp' => ['mode' => 'none', 'tools' => []],
                    ],
                    'extensions' => ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
                ],
            ],
        ], RunStartedMetadataDTO::class);

        $this->assertInstanceOf(RunStartedMetadataDTO::class, $dto);
        $this->assertTrue($dto->isAgentChild());
        $this->assertNull($dto->session->childKind);
        $this->assertSame('parent-1', $dto->session->parentRunId);
        $this->assertSame('scout', $dto->session->agentName);
        $this->assertSame('agent_abc123', $dto->session->artifactId);
        $this->assertFalse($dto->session->interactive);
        $this->assertSame('deepseek/deepseek-v4-flash', $dto->model);
        $this->assertSame('medium', $dto->reasoning);
        $this->assertSame(128000, $dto->contextWindow);
        $this->assertSame(['read', 'bash'], $dto->allowedToolsForChild());
        $this->assertSame(
            ['Ineersa\\CodingAgent\\Extension\\Builtin\\SafeGuard\\SafeGuardExtension'],
            $dto->allowedExtensionsForChild(),
        );
    }

    public function testCanonicalForkEnvelopeDenormalizesChildKind(): void
    {
        $dto = $this->denormalizer->denormalize([
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
                    'reasoning' => 'medium',
                    'tools_scope' => [
                        'allowed_tools' => ['read'],
                        'mcp' => [],
                    ],
                    'extensions' => [],
                ],
            ],
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
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'parent-1',
                        'agent_name' => 'scout',
                        'artifact_id' => 'agent_abc',
                    ],
                    'model' => 'openai/gpt-5',
                    'reasoning' => 'medium',
                    'tools_scope' => [
                        'allowed_tools' => ['read'],
                    ],
                ],
            ],
        ], RunStartedMetadataDTO::class);

        $this->assertInstanceOf(RunStartedMetadataDTO::class, $dto);
        $this->assertTrue($dto->isAgentChild());
        $this->assertSame(['read'], $dto->allowedToolsForChild());
        $this->assertSame([], $dto->allowedExtensionsForChild());
        $this->assertSame('openai/gpt-5', $dto->model);
        $this->assertTrue($dto->session->interactive);
        $this->assertNull($dto->contextWindow);
    }

    public function testMissingRequiredModelFailsStrictly(): void
    {
        // Missing model fails at Serializer constructor-arg check before DTO trim guard.
        $this->expectException(SerializerExceptionInterface::class);
        $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => [],
                ],
            ],
        ], RunStartedMetadataDTO::class);
    }

    public function testBlankModelFailsStrictly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('metadata.model is required');
        $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => [],
                    'model' => '   ',
                ],
            ],
        ], RunStartedMetadataDTO::class);
    }

    public function testMissingRequiredEnvelopeFailsStrictly(): void
    {
        $this->expectException(SerializerExceptionInterface::class);
        $this->denormalizer->denormalize([], RunStartedMetadataDTO::class);
    }

    public function testMalformedNestedMetadataFailsStrictly(): void
    {
        // SerializedPath traversal rejects non-array intermediate nodes.
        $this->expectException(\Throwable::class);
        $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => 'x',
            ],
        ], RunStartedMetadataDTO::class);
    }

    public function testChildMissingIdentityFailsStrictly(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('parent_run_id');
        $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => ['kind' => 'agent_child'],
                    'model' => 'openai/gpt-5',
                    'reasoning' => 'medium',
                    'tools_scope' => ['allowed_tools' => []],
                ],
            ],
        ], RunStartedMetadataDTO::class);
    }

    public function testParentDoesNotClassifyAsChild(): void
    {
        $parent = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => ['kind' => 'parent'],
                    'model' => 'openai/gpt-5',
                ],
            ],
        ], RunStartedMetadataDTO::class);

        $this->assertInstanceOf(RunStartedMetadataDTO::class, $parent);
        $this->assertFalse($parent->isAgentChild());
        $this->assertNull($parent->allowedToolsForChild());
        $this->assertNull($parent->allowedExtensionsForChild());
        $this->assertNull($parent->reasoning);
        $this->assertTrue($parent->session->interactive);
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
                            'agent_name' => 'fork',
                            'artifact_id' => 'agent_f9',
                            'interactive' => true,
                        ],
                        'model' => 'm',
                        'reasoning' => 'medium',
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

        $reader = new RunStartedMetadataReader($store, $this->denormalizer);
        $this->assertSame(['bash'], $reader->readAllowedTools($runId));
        $this->assertSame([], $reader->readAllowedExtensions($runId));

        $typed = $reader->readRunStartedMetadata($runId);
        $this->assertNotNull($typed);
        $this->assertTrue($typed->isAgentChild());
        $this->assertSame('parent-9', $typed->session->parentRunId);
        $this->assertSame('fork', $typed->session->childKind);
        $this->assertSame('m', $typed->model);
    }

    public function testMissingRunStartedReturnsNullLaunchMetadata(): void
    {
        $reader = new RunStartedMetadataReader(new InMemoryEventStore(), $this->denormalizer);
        $this->assertNull($reader->readRunStartedMetadata('missing'));
        $this->assertNull($reader->readAllowedTools('missing'));
        $this->assertNull($reader->readAllowedExtensions('missing'));
    }

    public function testInteractiveLiteralBoolIsPreservedWithDefaultTrue(): void
    {
        $false = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'p',
                        'agent_name' => 'scout',
                        'artifact_id' => 'a',
                        'interactive' => false,
                    ],
                    'model' => 'openai/gpt-5',
                    'reasoning' => 'medium',
                    'tools_scope' => ['allowed_tools' => []],
                ],
            ],
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $false);
        $this->assertFalse($false->session->interactive);

        $true = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'p',
                        'agent_name' => 'scout',
                        'artifact_id' => 'a',
                        'interactive' => true,
                    ],
                    'model' => 'openai/gpt-5',
                    'reasoning' => 'medium',
                    'tools_scope' => ['allowed_tools' => []],
                ],
            ],
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $true);
        $this->assertTrue($true->session->interactive);

        $absent = $this->denormalizer->denormalize([
            'payload' => [
                'metadata' => [
                    'session' => [
                        'kind' => 'agent_child',
                        'parent_run_id' => 'p',
                        'agent_name' => 'scout',
                        'artifact_id' => 'a',
                    ],
                    'model' => 'openai/gpt-5',
                    'reasoning' => 'medium',
                    'tools_scope' => ['allowed_tools' => []],
                ],
            ],
        ], RunStartedMetadataDTO::class);
        $this->assertInstanceOf(RunStartedMetadataDTO::class, $absent);
        $this->assertTrue($absent->session->interactive);
    }
}
