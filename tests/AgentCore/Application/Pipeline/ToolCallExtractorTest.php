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

        $this->assertNotNull($payload);
        $this->assertSame('interrupt', $payload['kind'], 'kind must survive from interrupt array');
        $this->assertSame('tc-1', $payload['tool_call_id'], 'tool_call_id must come from message');
        $this->assertSame('ask_human', $payload['tool_name'], 'tool_name from outer result');
        $this->assertSame('ah_abc123', $payload['question_id']);
        $this->assertSame('Approve the change?', $payload['prompt']);
        $this->assertSame(['type' => 'object', 'properties' => ['choice' => ['type' => 'string']]], $payload['schema']);
        $this->assertSame('approval', $payload['ui_kind']);
        $this->assertSame('Confirm Action', $payload['header']);
        $this->assertSame([['label' => 'Yes', 'description' => 'Approve'], ['label' => 'No', 'description' => 'Cancel']], $payload['choices']);
        $this->assertFalse($payload['default']);
        $this->assertFalse($payload['allow_other']);
        $this->assertFalse($payload['secret']);
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

        $this->assertNotNull($payload);
        $this->assertArrayHasKey('default', $payload, 'default key must survive even when null');
        $this->assertNull($payload['default']);
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

        $this->assertNotNull($payload);
        $this->assertSame('tc-kind', $payload['tool_call_id']);
        $this->assertSame('q-old-path', $payload['question_id']);
        $this->assertSame('Go?', $payload['prompt']);
        $this->assertSame(['type' => 'boolean'], $payload['schema']);
        $this->assertArrayNotHasKey('tool_name', $payload, 'tool_name must not be synthesized when absent from both paths');
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

        $this->assertNotNull($payload);
        $this->assertSame('tc-fallback', $payload['question_id'], 'question_id falls back to toolCallId');
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

        $this->assertNotNull($payload);
        $this->assertSame('Human input required.', $payload['prompt']);
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

        $this->assertNotNull($payload);
        $this->assertSame(['type' => 'string'], $payload['schema']);
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

        $this->assertNull($this->extractor->interruptPayloadFromToolResult($result));
    }

    public function testErrorResultReturnsNull(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-err')
            ->withIsError(true)
            ->withError(['message' => 'Timeout'])
            ->build();

        $this->assertNull($this->extractor->interruptPayloadFromToolResult($result));
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

        $this->assertNotNull($payload);
        $this->assertSame('confirm', $payload['ui_kind']);
        $this->assertArrayNotHasKey('header', $payload, 'header must not be synthesized when absent');
        $this->assertArrayNotHasKey('choices', $payload, 'choices must not be synthesized when absent');
        $this->assertArrayNotHasKey('default', $payload, 'default must not be synthesized when absent');
        $this->assertArrayNotHasKey('allow_other', $payload, 'allow_other must not be synthesized when absent');
        $this->assertArrayNotHasKey('secret', $payload, 'secret must not be synthesized when absent');
    }

    public function testToolNamePassthroughWhenOuterResultLacksIt(): void
    {
        // Interrupt array carries tool_name; outer result has NO tool_name key.
        // The guard reads the outer result — when absent, the interrupt passthrough value stands.
        $result = ToolCallResultBuilder::create('run-tn')
            ->withToolCallId('tc-1')
            ->withResult([
                'details' => [
                    'kind' => 'interrupt',
                    'question_id' => 'ah_xyz',
                    'prompt' => 'Choose?',
                    'tool_name' => 'ask_human',
                ],
                // NOTE: deliberately NO outer 'tool_name' key
            ])
            ->build();

        $payload = $this->extractor->interruptPayloadFromToolResult($result);

        $this->assertNotNull($payload);
        $this->assertSame('ask_human', $payload['tool_name'], 'tool_name from interrupt array survives when outer result lacks it');
    }

    public function testNonArrayResultReturnsNull(): void
    {
        $result = ToolCallResultBuilder::create('run-qh-05')
            ->withToolCallId('tc-nonarray')
            ->withResult('not an array')
            ->build();

        $this->assertNull($this->extractor->interruptPayloadFromToolResult($result));
    }
}
