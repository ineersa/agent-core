<?php

declare(strict_types=1);

namespace Ineersa\AgentCore\Tests\Application\Pipeline;

use Ineersa\AgentCore\Application\Pipeline\ToolCallExtractor;
use Ineersa\AgentCore\Tests\Support\Builder\ToolCallResultBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @see ToolCallExtractor::interruptPayloadFromToolResult()
 *
 * Test thesis: an interrupt payload shaped like AskHumanPayloadFactory output
 * is preserved end-to-end through the extractor so all keys reach the
 * waiting_human event. AgentCore does not enumerate UI fields — they pass
 * through generically.
 */
final class ToolCallExtractorTest extends TestCase
{
    private ToolCallExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new ToolCallExtractor();
    }

    public function testRichInterruptInDetailsPreservesAllFields(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-1')
            ->withResult([
                'tool_name' => 'ask_human',
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'ah_abc123',
                    'prompt' => 'Approve the change?',
                    'schema' => ['type' => 'object', 'properties' => ['choice' => ['type' => 'string']]],
                    'ui_kind' => 'approval',
                    'header' => 'Confirm Action',
                    'choices' => [['label' => 'Yes', 'description' => 'Approve'], ['label' => 'No', 'description' => 'Cancel']],
                    'default' => false,
                    'allow_other' => false,
                    'secret' => false,
                    'tool_name' => 'ask_human',  // deliberately present here too
                ],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertSame('tc-1', $payload['tool_call_id'], 'tool_call_id must come from message');
        self::assertSame('ask_human', $payload['tool_name'], 'tool_name from outer result');
        self::assertSame('ah_abc123', $payload['question_id']);
        self::assertSame('Approve the change?', $payload['prompt']);
        self::assertSame(['type' => 'object', 'properties' => ['choice' => ['type' => 'string']]], $payload['schema']);
        self::assertSame('approval', $payload['ui_kind']);
        self::assertSame('Confirm Action', $payload['header']);
        self::assertSame([['label' => 'Yes', 'description' => 'Approve'], ['label' => 'No', 'description' => 'Cancel']], $payload['choices']);
        self::assertFalse($payload['default']);
        self::assertFalse($payload['allow_other']);
        self::assertFalse($payload['secret']);
    }

    public function testDefaultNullSurvivesNoBlanketArrayFilter(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-dflt')
            ->withResult([
                'tool_name' => 'ask_human',
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'q-default-null',
                    'prompt' => 'Pick one',
                    'schema' => ['type' => 'string'],
                    'default' => null,
                ],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertArrayHasKey('default', $payload, 'default key must survive even when null');
        self::assertNull($payload['default']);
    }

    public function testInterruptInResultKind(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-kind')
            ->withResult([
                'kind' => 'interrupt',
                'question_id' => 'q-old-path',
                'prompt' => 'Go?',
                'schema' => ['type' => 'boolean'],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertSame('tc-kind', $payload['tool_call_id']);
        self::assertSame('q-old-path', $payload['question_id']);
        self::assertSame('Go?', $payload['prompt']);
        self::assertSame(['type' => 'boolean'], $payload['schema']);
    }

    public function testMissingQuestionIdFallsBackToToolCallId(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-fallback')
            ->withResult([
                'tool_name' => 'ask_human',
                'details' => [
                    'kind' => 'interrupt',
                    'prompt' => 'Proceed?',
                    'schema' => ['type' => 'boolean'],
                ],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertSame('tc-fallback', $payload['question_id'], 'question_id falls back to toolCallId');
    }

    public function testMissingPromptFallsBackToDefault(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-prompt')
            ->withResult([
                'tool_name' => 'ask_human',
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'q-no-prompt',
                    'schema' => ['type' => 'boolean'],
                ],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertSame('Human input required.', $payload['prompt']);
    }

    public function testMissingSchemaFallsBackToString(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-schema')
            ->withResult([
                'tool_name' => 'ask_human',
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'q-no-schema',
                    'prompt' => 'Enter text',
                ],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertSame(['type' => 'string'], $payload['schema']);
    }

    public function testNonInterruptResultReturnsNull(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-normal')
            ->withResult([
                'tool_name' => 'write_file',
                'content' => [['type' => 'text', 'text' => 'ok']],
            ])
            ->build();

        self::assertNull($this->extractor->interruptPayloadFromToolResult($result));
    }

    public function testErrorResultReturnsNull(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-err')
            ->withIsError(true)
            ->withError(['message' => 'Timeout'])
            ->build();

        self::assertNull($this->extractor->interruptPayloadFromToolResult($result));
    }

    public function testPartialUiFieldsPreservedNoSynthesis(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-partial')
            ->withResult([
                'tool_name' => 'ask_human',
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'q-partial',
                    'prompt' => 'Confirm?',
                    'schema' => ['type' => 'boolean'],
                    'ui_kind' => 'confirm',
                ],
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        self::assertNotNull($payload);
        self::assertSame('confirm', $payload['ui_kind']);
        self::assertArrayNotHasKey('header', $payload, 'header must not be synthesized when absent');
        self::assertArrayNotHasKey('choices', $payload, 'choices must not be synthesized when absent');
        self::assertArrayNotHasKey('default', $payload, 'default must not be synthesized when absent');
        self::assertArrayNotHasKey('allow_other', $payload, 'allow_other must not be synthesized when absent');
        self::assertArrayNotHasKey('secret', $payload, 'secret must not be synthesized when absent');
    }

    public function testNonArrayResultReturnsNull(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-nonarray')
            ->withResult('not an array')
            ->build();

        self::assertNull($this->extractor->interruptPayloadFromToolResult($result));
    }
}
