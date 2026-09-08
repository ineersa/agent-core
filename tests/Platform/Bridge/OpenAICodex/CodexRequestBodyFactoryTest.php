<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;

final class CodexRequestBodyFactoryTest extends TestCase
{
    public function testUpdatePreservesHistoryAndDoesNotAccumulate(): void
    {
        $factory = new CodexRequestBodyFactory();
        $model = new CodexModel('gpt-6-astra');
        $history = [['role' => 'user', 'content' => 'first'], ['role' => 'assistant', 'content' => 'answer']];
        $tool = ['type' => 'function_call_output', 'call_id' => 'call-1', 'output' => 'done'];
        $options = ['reasoning' => ['effort' => 'medium'], CodexRequestBodyFactory::REASONING_UPDATE => 'high'];
        $body = $factory->build($model, ['input' => [...$history, $tool]], $options);
        $this->assertSame([...$history, ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']], $tool], $body['input']);
        $this->assertSame('medium', $body['reasoning']['effort']);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_UPDATE, $body);
        $this->assertSame($body, $factory->build($model, $body, $options));
        $this->assertArrayNotHasKey('truncation', $body);
        $this->assertArrayNotHasKey('context_management', $body);
    }

    public function testNoInputNeverBecomesUpdateOnlyAndEmptyDeltaStaysEmpty(): void
    {
        $factory = new CodexRequestBodyFactory();
        $model = new CodexModel('gpt-6-astra');
        $options = ['reasoning' => ['effort' => 'medium'], CodexRequestBodyFactory::REASONING_UPDATE => 'high'];
        foreach ([[], [['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']]]] as $input) {
            $this->assertSame([], $factory->build($model, ['input' => $input], $options)['input']);
        }
        $first = $factory->build($model, ['input' => [['role' => 'user', 'content' => 'first']]], ['reasoning' => ['effort' => 'medium']]);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($first, 'resp-1', []);
        $repeated = $factory->build($model, ['input' => $first['input']], $options);
        $this->assertSame(['previous_response_id' => 'resp-1', 'input' => []], $state->buildDeltaRequest($repeated));
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
