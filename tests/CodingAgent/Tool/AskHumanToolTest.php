<?php

declare(strict_types=1);

namespace Ineersa\CodingAgent\Tests\Tool;

use Ineersa\CodingAgent\Tests\Tool\Support\NativeToolSchemaProbe;
use Ineersa\CodingAgent\Tool\AskHuman\AskHumanPayloadFactory;
use Ineersa\CodingAgent\Tool\AskHumanTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\PropertyInfo\Extractor\ReflectionExtractor;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
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

    private Serializer $serializer;

    private \Symfony\Component\Validator\Validator\ValidatorInterface $validator;

    protected function setUp(): void
    {
        $classMetadataFactory = new ClassMetadataFactory(new AttributeLoader());
        $this->serializer = new Serializer([
            new ObjectNormalizer(
                classMetadataFactory: $classMetadataFactory,
                nameConverter: new MetadataAwareNameConverter($classMetadataFactory, new CamelCaseToSnakeCaseNameConverter()),
                propertyTypeExtractor: new ReflectionExtractor(),
            ),
        ]);
        $this->validator = (new ValidatorBuilder())->enableAttributeMapping()->getValidator();

        $this->tool = new AskHumanTool(new AskHumanPayloadFactory());
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
        // Typed DTO tool: schema is generated natively from AskHumanArgumentsDTO.
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->tool);
        $args = $schema['properties']['arguments']['properties'];

        $this->assertSame('object', $schema['type']);
        $this->assertArrayHasKey('question', $args);
        $this->assertArrayNotHasKey('prompt', $args);
        $this->assertContains('question', $schema['properties']['arguments']['required']);
        $this->assertFalse($schema['properties']['arguments']['additionalProperties']);
    }

    public function testDefinitionSchemaHasNoSchemaProperty(): void
    {
        $definition = $this->tool->definition();
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->tool);
        $args = $schema['properties']['arguments']['properties'];

        $this->assertArrayNotHasKey('schema', $args);
        $this->assertArrayHasKey('kind', $args);
        $this->assertSame(['confirm'], $args['kind']['enum']);
        $this->assertArrayNotHasKey('ui_kind', $args);
        $this->assertArrayHasKey('choices', $args);
        $this->assertArrayNotHasKey('default', $args);
        $this->assertArrayNotHasKey('question_id', $args);
        $this->assertArrayHasKey('header', $args);
        $this->assertArrayNotHasKey('allow_other', $args);
    }

    public function testDefinitionChoicesItemsIsStringOnly(): void
    {
        $definition = $this->tool->definition();
        $this->assertNull($definition->parametersJsonSchema);

        $schema = NativeToolSchemaProbe::for($this->tool);
        $items = $schema['properties']['arguments']['properties']['choices']['items'];

        $this->assertSame(['type' => 'string'], $items);
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

    /* ── __invoke() returns immediately with interrupt payload ── */

    public function testInvokeReturnsImmediatelyWithInterruptKind(): void
    {
        $result = $this->invoke(['question' => 'What is your name?']);

        $this->assertIsArray($result);
        $this->assertSame('interrupt', $result['kind']);
    }

    public function testInvokeReturnsPromptFromQuestion(): void
    {
        $result = $this->invoke(['question' => 'Approve?']);

        $this->assertSame('Approve?', $result['prompt']);
    }

    public function testRejectsMissingQuestion(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('question');

        $this->invoke([]);
    }

    public function testInvokeGeneratesStableQuestionId(): void
    {
        $first = $this->invoke(['question' => 'Same question?']);
        $second = $this->invoke(['question' => 'Same question?']);

        $this->assertArrayHasKey('question_id', $first);
        $this->assertSame($first['question_id'], $second['question_id']);
    }

    public function testInvokeReturnsDefaultSchemaWhenNoneProvided(): void
    {
        $result = $this->invoke(['question' => 'Enter text:']);

        $this->assertArrayHasKey('schema', $result);
        $this->assertSame(['type' => 'string'], $result['schema']);
    }

    /* ── Text question ── */

    public function testTextQuestionDefaultKind(): void
    {
        $result = $this->invoke(['question' => 'Enter name:']);

        // No choices, no boolean schema -> text
        $this->assertArrayHasKey('ui_kind', $result);
        $this->assertSame('text', $result['ui_kind']);
    }

    /* ── Confirm/boolean question ── */

    public function testConfirmKindDerivesBooleanSchema(): void
    {
        $result = $this->invoke([
            'question' => 'Are you sure?',
            'kind' => 'confirm',
        ]);

        $this->assertSame('confirm', $result['ui_kind']);
        $this->assertSame(['type' => 'boolean'], $result['schema']);
    }

    /* ── Choice question with bare string choices ── */

    public function testChoiceQuestionNormalizesBareStrings(): void
    {
        $result = $this->invoke([
            'question' => 'Pick one:',
            'choices' => ['simple', 'robust', 'fast'],
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
        $result = $this->invoke([
            'question' => 'Pick:',
            'choices' => ['option-a', 'option-b'],
        ]);

        $this->assertArrayHasKey('enum', $result['schema']);
        $this->assertSame(['option-a', 'option-b'], $result['schema']['enum']);
    }

    /* ── Optional metadata ── */

    public function testPreservesHeader(): void
    {
        $result = $this->invoke([
            'question' => 'Proceed?',
            'header' => 'Destructive Action',
        ]);

        $this->assertSame('Destructive Action', $result['header']);
    }

    /* ── Edge cases ── */

    public function testEmptyHeaderIsNotIncluded(): void
    {
        $result = $this->invoke([
            'question' => 'Proceed?',
            'header' => '',
        ]);

        $this->assertArrayNotHasKey('header', $result);
    }

    public function testQuestionIdPrefix(): void
    {
        $result = $this->invoke(['question' => 'Test?']);

        $this->assertStringStartsWith('ah_', $result['question_id']);
    }

    /* ── Validation ── */

    public function testRejectsEmptyQuestion(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('question');

        $this->invoke(['question' => '']);
    }

    #[DataProvider('invalidKindProvider')]
    public function testRejectsInvalidKind(string $kind): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('Unsupported kind');

        $this->invoke([
            'question' => 'Approve deployment?',
            'kind' => $kind,
        ]);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidKindProvider(): iterable
    {
        yield 'approval' => ['approval'];
        yield 'text' => ['text'];
        yield 'choice' => ['choice'];
    }

    public function testFormerUiKindInputAliasIsIgnored(): void
    {
        $result = $this->invoke([
            'question' => 'Test?',
            'ui_kind' => 'confirm',
        ]);

        $this->assertSame('text', $result['ui_kind']);
    }

    public function testRejectsNestedObjectChoices(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('non-empty string');

        $this->invoke([
            'question' => 'Pick one:',
            'choices' => [
                ['label' => 'First', 'description' => 'The first option'],
            ],
        ]);
    }

    public function testRejectsEmptyChoicesList(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('At least one');

        $this->invoke([
            'question' => 'Pick one:',
            'choices' => [],
        ]);
    }

    public function testRejectsConfirmWithChoices(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('mutually exclusive');

        $this->invoke([
            'question' => 'Proceed?',
            'kind' => 'confirm',
            'choices' => ['yes', 'no'],
        ]);
    }

    public function testRejectsEmptyStringChoice(): void
    {
        $this->expectException(\Ineersa\AgentCore\Contract\Tool\ToolCallException::class);
        $this->expectExceptionMessage('non-empty string');

        $this->invoke([
            'question' => 'Pick one:',
            'choices' => ['valid', ''],
        ]);
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function invoke(array $arguments): array
    {
        try {
            /** @var \Ineersa\CodingAgent\Tool\AskHuman\AskHumanArgumentsDTO $dto */
            $dto = $this->serializer->denormalize($arguments, \Ineersa\CodingAgent\Tool\AskHuman\AskHumanArgumentsDTO::class);
        } catch (\Throwable $e) {
            throw new \Ineersa\AgentCore\Contract\Tool\ToolCallException('Invalid ask_human arguments: '.$e->getMessage(), retryable: false);
        }

        $violations = $this->validator->validate($dto);
        if ($violations->count() > 0) {
            $messages = [];
            foreach ($violations as $violation) {
                $path = $violation->getPropertyPath();
                $messages[] = '' !== $path ? $path.': '.$violation->getMessage() : $violation->getMessage();
            }

            throw new \Ineersa\AgentCore\Contract\Tool\ToolCallException(implode('; ', $messages), retryable: false);
        }

        return ($this->tool)($dto);
    }
}
