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

    public function testInteractiveOnlyLiteralBoolIsDecoded(): void
    {
        $decoder = new RunStartedMetadataDecoder();

        $false = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child', 'interactive' => false],
        ]);
        $this->assertFalse($false->session->interactive);

        $true = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child', 'interactive' => true],
        ]);
        $this->assertTrue($true->session->interactive);

        $absent = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child'],
        ]);
        $this->assertNull($absent->session->interactive);

        foreach ([0, '0', '', null, 1, 'false', []] as $malformed) {
            $dto = $decoder->fromMetadataArray([
                'session' => ['kind' => 'agent_child', 'interactive' => $malformed],
            ]);
            $this->assertNull(
                $dto->session->interactive,
                'interactive='.var_export($malformed, true).' must not coerce to bool',
            );
            // Probe historical default: interactive=true when not literal false.
            $this->assertFalse(false === ($dto->session->interactive ?? true));
        }
    }

    public function testModelProviderPreserveExactNonEmptyIncludingWhitespace(): void
    {
        $decoder = new RunStartedMetadataDecoder();
        $paddedModel = '  deepseek/deepseek-v4-flash  ';
        $paddedProvider = ' deepseek ';

        $dto = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child', 'parent_run_id' => 'parent-1'],
            'model' => $paddedModel,
            'provider' => $paddedProvider,
            'reasoning' => ' medium ',
        ]);

        $this->assertSame($paddedModel, $dto->model);
        $this->assertSame($paddedProvider, $dto->provider);
        $this->assertSame(' medium ', $dto->reasoning);

        $empty = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child'],
            'model' => '',
            'provider' => '',
            'reasoning' => '',
        ]);
        $this->assertNull($empty->model);
        $this->assertNull($empty->provider);
        $this->assertNull($empty->reasoning);

        // Whitespace-only is non-empty for progress/deferred (exact '' check only).
        $wsOnly = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child'],
            'model' => '   ',
            'provider' => "\t",
        ]);
        $this->assertSame('   ', $wsOnly->model);
        $this->assertSame("\t", $wsOnly->provider);
    }

    public function testParentRunIdRequiresNonBlankButReturnsExactOriginal(): void
    {
        $decoder = new RunStartedMetadataDecoder();

        $padded = $decoder->fromMetadataArray([
            'session' => [
                'kind' => 'agent_child',
                'parent_run_id' => '  parent-xyz  ',
            ],
        ]);
        $this->assertSame('  parent-xyz  ', $padded->session->parentRunId);

        $blank = $decoder->fromMetadataArray([
            'session' => [
                'kind' => 'agent_child',
                'parent_run_id' => '   ',
            ],
        ]);
        $this->assertNull($blank->session->parentRunId);

        $empty = $decoder->fromMetadataArray([
            'session' => [
                'kind' => 'agent_child',
                'parent_run_id' => '',
            ],
        ]);
        $this->assertNull($empty->session->parentRunId);
    }

    public function testKindAndChildFieldsPreserveExactStringsWithoutTrim(): void
    {
        $decoder = new RunStartedMetadataDecoder();

        // Padded kind must NOT classify as agent_child.
        $paddedKind = $decoder->fromMetadataArray([
            'session' => ['kind' => ' agent_child '],
        ]);
        $this->assertSame(' agent_child ', $paddedKind->session->kind);
        $this->assertFalse($paddedKind->isAgentChild());

        $childFields = $decoder->fromMetadataArray([
            'session' => [
                'kind' => 'agent_child',
                'child_kind' => ' fork ',
                'agent_name' => ' scout ',
                'artifact_id' => ' agent_abc ',
            ],
        ]);
        $this->assertSame(' fork ', $childFields->session->childKind);
        $this->assertSame(' scout ', $childFields->session->agentName);
        $this->assertSame(' agent_abc ', $childFields->session->artifactId);
        // isForkChild requires exact 'fork'.
        $this->assertFalse($childFields->session->isForkChild());
    }

    public function testExtensionsStillTrimAndDropBlankEntries(): void
    {
        $decoder = new RunStartedMetadataDecoder();
        $dto = $decoder->fromMetadataArray([
            'session' => ['kind' => 'agent_child'],
            'extensions' => ['  ExtA  ', '', '   ', 'ExtB', 12, null],
        ]);

        $this->assertSame(['ExtA', 'ExtB'], $dto->allowedExtensionsForChild());
    }
}
