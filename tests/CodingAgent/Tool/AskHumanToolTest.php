<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\AgentCore\Application\Handler\ToolExecutionResultStore;
use Ineersa\AgentCore\Application\Handler\ToolExecutor;
use Ineersa\AgentCore\Domain\Tool\ToolExecutionMode;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallBuilder;
use Ineersa\CodingAgent\Tool\AskHumanTool;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;

/**
 * @covers \Ineersa\CodingAgent\Tool\AskHumanTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 */
final class AskHumanToolTest extends TestCase
{
    private AskHumanTool $tool;

    protected function setUp(): void
    {
        $this->tool = new AskHumanTool();
    }

    /* ── definition() tests ── */

    public function testDefinitionNameIsAskHuman(): void
    {
        $definition = $this->tool->definition();

        $this->assertSame('ask_human', $definition->name);
    }

    public function testDefinitionExecutionModeIsInterrupt(): void
    {
        $definition = $this->tool->definition();

        $this->assertSame(ToolExecutionMode::Interrupt, $definition->executionMode);
    }

    public function testDefinitionHasRequiredQuestionProperty(): void
    {
        $definition = $this->tool->definition();
        $schema = $definition->parametersJsonSchema;

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('question', $schema['properties']);
        $this->assertContains('question', $schema['required']);
        $this->assertArrayHasKey('additionalProperties', $schema);
        $this->assertFalse($schema['additionalProperties']);
    }

    public function testDefinitionSchemaHasMetadataProperties(): void
    {
        $definition = $this->tool->definition();
        $properties = $definition->parametersJsonSchema['properties'];

        $this->assertArrayHasKey('schema', $properties);
        $this->assertArrayHasKey('kind', $properties);
        $this->assertArrayHasKey('choices', $properties);
        $this->assertArrayHasKey('default', $properties);
        $this->assertArrayHasKey('question_id', $properties);
        $this->assertArrayHasKey('header', $properties);
        $this->assertArrayHasKey('allow_other', $properties);
        $this->assertArrayHasKey('secret', $properties);
    }

    public function testDefinitionHandlerIsInvokable(): void
    {
        $definition = $this->tool->definition();

        $this->assertInstanceOf(ToolHandlerInterface::class, $definition->handler);
    }

    public function testDefinitionHasPromptLine(): void
    {
        $definition = $this->tool->definition();

        $this->assertNotEmpty($definition->promptLine);
        $this->assertStringContainsString('ask_human', $definition->promptLine);
    }

    public function testDefinitionHasGuidelines(): void
    {
        $definition = $this->tool->definition();

        $this->assertNotEmpty($definition->promptGuidelines);
    }

    /* ── ToolRegistry discovery test ── */

    public function testRegistryExposesAskHumanTool(): void
    {
        $registry = new ToolRegistry([$this->tool]);
        $toolbox = new RegistryBackedToolbox($registry);
        $tools = $toolbox->getTools();

        $toolNames = array_map(static fn ($t) => $t->getName(), $tools);

        $this->assertContains('ask_human', $toolNames);
    }

    public function testRegistryPermanentMetadataIncludesAskHuman(): void
    {
        $registry = new ToolRegistry([$this->tool]);

        $definitions = $registry->activeToolDefinitions();

        $names = array_map(static fn ($d) => $d->name, $definitions);

        $this->assertContains('ask_human', $names);
    }

    /* ── __invoke() returns immediately with interrupt payload ── */

    public function testInvokeReturnsImmediatelyWithInterruptKind(): void
    {
        $result = ($this->tool)(['question' => 'What is your name?']);

        $this->assertIsArray($result);
        $this->assertSame('interrupt', $result['kind']);
    }

    public function testInvokeReturnsPromptFromQuestion(): void
    {
        $result = ($this->tool)(['question' => 'Approve?']);

        $this->assertSame('Approve?', $result['prompt']);
    }

    public function testInvokePrefersPromptOverQuestion(): void
    {
        $result = ($this->tool)([
            'question' => 'Approve?',
            'prompt' => 'Please approve this action.',
        ]);

        $this->assertSame('Please approve this action.', $result['prompt']);
    }

    public function testInvokeUsesFallbackPromptWhenMissing(): void
    {
        $result = ($this->tool)([]);

        $this->assertSame('Please provide input.', $result['prompt']);
    }

    public function testInvokeGeneratesStableQuestionId(): void
    {
        $first = ($this->tool)(['question' => 'Same question?']);
        $second = ($this->tool)(['question' => 'Same question?']);

        $this->assertArrayHasKey('question_id', $first);
        $this->assertSame($first['question_id'], $second['question_id']);
    }

    public function testInvokeUsesProvidedQuestionId(): void
    {
        $result = ($this->tool)([
            'question' => 'Approve?',
            'question_id' => 'my-custom-id',
        ]);

        $this->assertSame('my-custom-id', $result['question_id']);
    }

    public function testInvokeReturnsDefaultSchemaWhenNoneProvided(): void
    {
        $result = ($this->tool)(['question' => 'Enter text:']);

        $this->assertArrayHasKey('schema', $result);
        $this->assertSame(['type' => 'string'], $result['schema']);
    }

    public function testInvokeReturnsProvidedSchema(): void
    {
        $result = ($this->tool)([
            'question' => 'Confirm?',
            'schema' => ['type' => 'boolean'],
        ]);

        $this->assertSame(['type' => 'boolean'], $result['schema']);
    }

    /* ── Text question ── */

    public function testTextQuestionDefaultKind(): void
    {
        $result = ($this->tool)(['question' => 'Enter name:']);

        // No choices, no boolean schema -> text
        $this->assertArrayHasKey('ui_kind', $result);
        $this->assertSame('text', $result['ui_kind']);
    }

    /* ── Confirm/boolean question ── */

    public function testConfirmQuestionWithBooleanSchema(): void
    {
        $result = ($this->tool)([
            'question' => 'Are you sure?',
            'schema' => ['type' => 'boolean'],
            'kind' => 'confirm',
        ]);

        $this->assertSame('confirm', $result['ui_kind']);
        $this->assertSame(['type' => 'boolean'], $result['schema']);
    }

    public function testConfirmQuestionDerivesKindFromSchema(): void
    {
        // When kind is absent but schema is boolean, kind should be 'confirm'
        $result = ($this->tool)([
            'question' => 'Proceed?',
            'schema' => ['type' => 'boolean'],
        ]);

        $this->assertSame('confirm', $result['ui_kind']);
    }

    /* ── Choice question with bare string choices ── */

    public function testChoiceQuestionNormalizesBareStrings(): void
    {
        $result = ($this->tool)([
            'question' => 'Pick one:',
            'choices' => ['simple', 'robust', 'fast'],
            'kind' => 'choice',
        ]);

        $this->assertSame('choice', $result['ui_kind']);
        $this->assertArrayHasKey('choices', $result);
        $this->assertCount(3, $result['choices']);

        $this->assertSame('simple', $result['choices'][0]['label']);
        $this->assertSame('', $result['choices'][0]['description']);

        $this->assertSame('robust', $result['choices'][1]['label']);
        $this->assertSame('fast', $result['choices'][2]['label']);
    }

    public function testChoiceQuestionPreservesStructuredChoices(): void
    {
        $result = ($this->tool)([
            'question' => 'Pick one:',
            'choices' => [
                ['label' => 'simple', 'description' => 'Fast, minimal change'],
                ['label' => 'robust', 'description' => 'More complete implementation'],
            ],
        ]);

        $this->assertCount(2, $result['choices']);
        $this->assertSame('simple', $result['choices'][0]['label']);
        $this->assertSame('Fast, minimal change', $result['choices'][0]['description']);
        $this->assertSame('robust', $result['choices'][1]['label']);
        $this->assertSame('More complete implementation', $result['choices'][1]['description']);
    }

    public function testChoiceQuestionDerivedSchemaHasEnum(): void
    {
        $result = ($this->tool)([
            'question' => 'Pick:',
            'choices' => ['option-a', 'option-b'],
        ]);

        $this->assertArrayHasKey('enum', $result['schema']);
        $this->assertSame(['option-a', 'option-b'], $result['schema']['enum']);
    }

    /* ── Approval question ── */

    public function testApprovalKind(): void
    {
        $result = ($this->tool)([
            'question' => 'Approve deployment?',
            'kind' => 'approval',
            'schema' => ['type' => 'boolean'],
            'default' => false,
        ]);

        $this->assertSame('approval', $result['ui_kind']);
        $this->assertSame(['type' => 'boolean'], $result['schema']);
    }

    /* ── Optional metadata ── */

    public function testPreservesHeader(): void
    {
        $result = ($this->tool)([
            'question' => 'Proceed?',
            'header' => 'Destructive Action',
        ]);

        $this->assertSame('Destructive Action', $result['header']);
    }

    public function testPreservesDefault(): void
    {
        $result = ($this->tool)([
            'question' => 'Proceed?',
            'schema' => ['type' => 'boolean'],
            'default' => true,
        ]);

        $this->assertTrue($result['default']);
    }

    public function testPreservesAllowOther(): void
    {
        $result = ($this->tool)([
            'question' => 'Choose:',
            'choices' => ['a', 'b'],
            'allow_other' => false,
        ]);

        $this->assertFalse($result['allow_other']);
    }

    public function testDoesNotIncludeAllowOtherWhenNotProvided(): void
    {
        $result = ($this->tool)([
            'question' => 'Choose:',
            'choices' => ['a', 'b'],
        ]);

        $this->assertArrayNotHasKey('allow_other', $result);
    }

    public function testPreservesSecret(): void
    {
        $result = ($this->tool)([
            'question' => 'Enter password:',
            'secret' => true,
        ]);

        $this->assertTrue($result['secret']);
    }

    public function testDoesNotIncludeSecretWhenNotProvided(): void
    {
        $result = ($this->tool)([
            'question' => 'Enter name:',
        ]);

        $this->assertArrayNotHasKey('secret', $result);
    }

    /* ── Edge cases ── */

    public function testEmptyHeaderIsNotIncluded(): void
    {
        $result = ($this->tool)([
            'question' => 'Proceed?',
            'header' => '',
        ]);

        $this->assertArrayNotHasKey('header', $result);
    }

    public function testQuestionIdPrefix(): void
    {
        $result = ($this->tool)(['question' => 'Test?']);

        $this->assertStringStartsWith('ah_', $result['question_id']);
    }

    public function testChoicesWithValueFieldWork(): void
    {
        $result = ($this->tool)([
            'question' => 'Pick:',
            'choices' => [
                ['label' => 'First', 'value' => 'fst', 'description' => 'The first option'],
            ],
        ]);

        $this->assertCount(1, $result['choices']);
        $this->assertSame('First', $result['choices'][0]['label']);
        $this->assertSame('fst', $result['choices'][0]['value']);
        $this->assertSame('The first option', $result['choices'][0]['description']);
    }

    /* ── Parity: ToolExecutor vs AskHumanTool ── */

    public function testParityAskHumanToolAndToolExecutorProduceIdenticalPayloads(): void
    {
        $executor = new ToolExecutor('sequential', 30, 2, new ToolExecutionResultStore());

        // Representative arguments exercising all derivation paths
        $args = [
            'question' => 'Select one:',
            'schema' => ['type' => 'string', 'enum' => ['simple', 'robust', 'fast']],
            'kind' => 'choice',
            'choices' => [
                ['label' => 'simple', 'description' => 'Minimal change'],
                'robust',
                ['label' => 'fast', 'value' => 'quick'],
            ],
            'header' => 'Implementation Strategy',
            'default' => 'simple',
            'allow_other' => true,
            'secret' => false,
        ];

        // Expected payload from AskHumanTool::buildInterruptPayload
        $expected = AskHumanTool::buildInterruptPayload($args);

        // Actual payload from ToolExecutor defensive fallback path
        $result = $executor->execute(ToolCallBuilder::create('parity-test')
            ->withToolName('ask_human')
            ->withArguments($args)
            ->withOrderIndex(0)
            ->build());

        $actual = $result->details;

        // Verify every field from AskHumanTool is present and identical in ToolExecutor output.
        // ToolExecutor details also include execution metadata (mode, timeout_seconds,
        // max_parallelism) added by withExecutionMetadata() — those are not part
        // of the interrupt payload proper and are not compared here.
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual, \sprintf('Payload missing key "%s" in ToolExecutor output', $key));
            $this->assertSame($value, $actual[$key], \sprintf('Payload mismatch for key "%s"', $key));
        }

        // Verify JSON content is consistent with details
        $decoded = json_decode($result->content[0]['text'], true);
        $this->assertIsArray($decoded);
        $this->assertSame($expected['kind'], $decoded['kind']);
        $this->assertSame($expected['question_id'], $decoded['question_id']);
        $this->assertSame($expected['prompt'], $decoded['prompt']);
        $this->assertSame($expected['schema'], $decoded['schema']);
        $this->assertSame($expected['ui_kind'], $decoded['ui_kind']);
        $this->assertSame($expected['choices'], $decoded['choices']);
    }

    public function testParityWithMinimalArgsAlsoMatches(): void
    {
        $executor = new ToolExecutor('sequential', 30, 2, new ToolExecutionResultStore());

        // Bare minimum: only question
        $args = ['question' => 'Hello?'];

        $expected = AskHumanTool::buildInterruptPayload($args);

        $result = $executor->execute(ToolCallBuilder::create('parity-min')
            ->withToolName('ask_human')
            ->withArguments($args)
            ->withOrderIndex(0)
            ->build());

        $actual = $result->details;

        // Compare all expected fields (actual has extra execution metadata keys)
        foreach ($expected as $key => $value) {
            $this->assertArrayHasKey($key, $actual, \sprintf('Minimal payload missing key "%s"', $key));
            $this->assertSame($value, $actual[$key], \sprintf('Minimal payload mismatch for key "%s"', $key));
        }
    }
}
