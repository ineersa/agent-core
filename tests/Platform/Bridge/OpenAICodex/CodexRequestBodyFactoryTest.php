<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;

final class CodexRequestBodyFactoryTest extends TestCase
{
    public function testInternalReasoningControlsAreStrippedWithoutMovingInput(): void
    {
        $factory = new CodexRequestBodyFactory();
        $model = new CodexModel('gpt-6-astra');
        $input = [
            ['role' => 'user', 'content' => 'first'],
            ['type' => 'message', 'role' => 'assistant', 'content' => 'answer'],
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
            ['role' => 'user', 'content' => 'second'],
        ];
        $options = [
            'reasoning' => ['effort' => 'medium'],
            CodexRequestBodyFactory::REASONING_UPDATE => 'high',
            'hatfield_run_id' => '55',
            'hatfield_model_ref' => 'openai-codex/gpt-6-astra',
        ];

        $body = $factory->build($model, ['input' => $input], $options);

        $this->assertSame($input, $body['input']);
        $this->assertSame('medium', $body['reasoning']['effort']);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_UPDATE, $body);
        $this->assertArrayNotHasKey('hatfield_run_id', $body);
        $this->assertArrayNotHasKey('hatfield_model_ref', $body);
        $this->assertArrayNotHasKey('truncation', $body);
        $this->assertArrayNotHasKey('context_management', $body);
    }

    public function testStableUpdatePrefixProducesEmptyDeltaOnRepeat(): void
    {
        $factory = new CodexRequestBodyFactory();
        $model = new CodexModel('gpt-6-astra');
        $first = $factory->build($model, [
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'second'],
            ],
        ], ['reasoning' => ['effort' => 'medium']]);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($first, 'resp-1', []);
        $repeated = $factory->build($model, ['input' => $first['input']], ['reasoning' => ['effort' => 'medium']]);

        $this->assertSame(['previous_response_id' => 'resp-1', 'input' => []], $state->decide($repeated)->delta);
    }

    public function testNonAstraShapingIsUnchanged(): void
    {
        $factory = new CodexRequestBodyFactory();
        $input = [['role' => 'user', 'content' => 'hello']];
        $body = $factory->build(new CodexModel('gpt-5.6-luna'), ['input' => $input], ['reasoning' => ['effort' => 'high'], CodexRequestBodyFactory::REASONING_UPDATE => 'low']);
        $this->assertSame($input, $body['input']);
        $this->assertSame('high', $body['reasoning']['effort']);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_UPDATE, $body);
    }
}
