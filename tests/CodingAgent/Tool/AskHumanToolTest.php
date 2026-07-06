<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tool\AskHuman\AskHumanPayloadFactory;
use Ineersa\CodingAgent\Tool\AskHumanTool;
use Ineersa\CodingAgent\Tool\RegistryBackedToolbox;
use Ineersa\CodingAgent\Tool\ToolHandlerInterface;
use Ineersa\CodingAgent\Tool\ToolRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\MetadataAwareNameConverter;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Validator\ValidatorBuilder;

/**
 * @covers \Ineersa\CodingAgent\Tool\AskHumanTool
 * @covers \Ineersa\CodingAgent\Tool\ToolDefinitionDTO
 * @covers \Ineersa\CodingAgent\Tool\AskHuman\AskHumanPayloadFactory
 * @covers \Ineersa\CodingAgent\Tool\AskHuman\AskHumanArgumentsDTO
 */
final class AskHumanToolTest extends TestCase
{
    private AskHumanTool $tool;

    protected function setUp(): void
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $serializer = new Serializer([
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory),
                propertyTypeExtractor: new ReflectionExtractor(),
            ),
        ]);
        $validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        $factory = new AskHumanPayloadFactory($serializer, $validator);
        $this->tool = new AskHumanTool($factory);
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

        $this->assertSame(\Ineersa\AgentCore\Domain\Tool\ToolExecutionMode::Interrupt, $definition->executionMode);
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

    public function testDefinitionSchemaHasNoSchemaProperty(): void
    {
        $definition = $this->tool->definition();
        $properties = $definition->parametersJsonSchema['properties'];

        $this->assertArrayNotHasKey('schema', $properties);
        $this->assertArrayHasKey('kind', $properties);
        $this->assertArrayHasKey('choices', $properties);
        $this->assertArrayHasKey('default', $properties);
        $this->assertArrayHasKey('question_id', $properties);
        $this->assertArrayHasKey('header', $properties);
        $this->assertArrayNotHasKey('allow_other', $properties);
    }

    public function testDefinitionChoicesItemsIsStringOnly(): void
    {
        $definition = $this->tool->definition();
        $items = $definition->parametersJsonSchema['properties']['choices']['items'];

        $this->assertSame(['type' => 'string'], $items);
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

    public function testInvokeAcceptsPromptAsAlias(): void
    {
        $result = ($this->tool)([
            'prompt' => 'Only prompt, no question.',
        ]);

        $this->assertIsArray($result);
        $this->assertSame('interrupt', $result['kind']);
        $this->assertSame('Only prompt, no question.', $result['prompt']);
        $this->assertArrayHasKey('question_id', $result);
        $this->assertStringStartsWith('ah_', $result['question_id']);
    }

    public function testRejectsMissingQuestion(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('question');

        ($this->tool)([]);
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

    /* ── Text question ── */

    public function testTextQuestionDefaultKind(): void
    {
        $result = ($this->tool)(['question' => 'Enter name:']);

        // No choices, no boolean schema -> text
        $this->assertArrayHasKey('ui_kind', $result);
        $this->assertSame('text', $result['ui_kind']);
    }

    /* ── Confirm/boolean question ── */

    public function testConfirmKindDerivesBooleanSchema(): void
    {
        $result = ($this->tool)([
            'question' => 'Are you sure?',
            'kind' => 'confirm',
        ]);

        $this->assertSame('confirm', $result['ui_kind']);
        $this->assertSame(['type' => 'boolean'], $result['schema']);
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
            'kind' => 'confirm',
            'default' => true,
        ]);

        $this->assertTrue($result['default']);
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

    /* ── Validation ── */

    public function testRejectsEmptyQuestion(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('question');

        ($this->tool)(['question' => '']);
    }

    public function testRejectsInvalidKind(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('Unsupported kind');

        ($this->tool)([
            'question' => 'Test?',
            'kind' => 'invalid_kind',
        ]);
    }

    public function testRejectsInvalidUiKind(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('Unsupported');

        ($this->tool)([
            'question' => 'Test?',
            'ui_kind' => 'bogus',
        ]);
    }

    public function testRejectsNestedObjectChoices(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('non-empty string');

        ($this->tool)([
            'question' => 'Pick one:',
            'choices' => [
                ['label' => 'First', 'description' => 'The first option'],
            ],
        ]);
    }

    public function testRejectsKindChoiceWithoutChoices(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('required when kind');

        ($this->tool)([
            'question' => 'Pick one:',
            'kind' => 'choice',
        ]);
    }

    public function testRejectsKindChoiceWithEmptyChoices(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('At least one');

        ($this->tool)([
            'question' => 'Pick one:',
            'kind' => 'choice',
            'choices' => [],
        ]);
    }

    public function testRejectsEmptyStringChoice(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('non-empty string');

        ($this->tool)([
            'question' => 'Pick one:',
            'choices' => ['valid', ''],
        ]);
    }
}
