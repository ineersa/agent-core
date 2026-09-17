<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexReasoningTransitionLedger;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexRequestBodyFactory;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;

final class CodexRequestBodyFactoryTest extends TestCase
{
    public function testUpdatePreservesStableHistoricalPositionAcrossAppends(): void
    {
        $ledger = new CodexReasoningTransitionLedger();
        $factory = new CodexRequestBodyFactory($ledger);
        $model = new CodexModel('gpt-6-astra');
        $cacheKey = 'cache-family-1';
        $history = [['role' => 'user', 'content' => 'first'], ['role' => 'assistant', 'content' => 'answer']];
        $tool = ['type' => 'function_call_output', 'call_id' => 'call-1', 'output' => 'done'];
        $options = [
            'prompt_cache_key' => $cacheKey,
            'reasoning' => ['effort' => 'medium'],
            CodexRequestBodyFactory::REASONING_UPDATE => 'high',
        ];

        $first = $factory->build($model, ['input' => [...$history, $tool]], $options);
        $expected = [...$history, ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']], $tool];
        $this->assertSame($expected, $first['input']);
        $this->assertSame('medium', $first['reasoning']['effort']);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_UPDATE, $first);

        $appended = [
            ...$history,
            $tool,
            ['role' => 'assistant', 'content' => 'after-tool'],
            ['role' => 'user', 'content' => 'second'],
        ];
        $second = $factory->build(
            $model,
            ['input' => $appended],
            ['prompt_cache_key' => $cacheKey, 'reasoning' => ['effort' => 'medium']],
        );
        $this->assertSame(
            [
                ...$history,
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                $tool,
                ['role' => 'assistant', 'content' => 'after-tool'],
                ['role' => 'user', 'content' => 'second'],
            ],
            $second['input'],
        );
        $this->assertSame(
            \array_slice($second['input'], 0, \count($expected)),
            $expected,
            'Previously emitted configuration_update must keep its historical offset.',
        );
    }

    public function testNoUpdateOptionLeavesHistoryUntouchedAndEmptyDeltaStaysEmpty(): void
    {
        $factory = new CodexRequestBodyFactory(new CodexReasoningTransitionLedger());
        $model = new CodexModel('gpt-6-astra');
        $options = ['prompt_cache_key' => 'cache-family-2', 'reasoning' => ['effort' => 'medium']];
        $input = [['role' => 'user', 'content' => 'first'], ['role' => 'assistant', 'content' => 'ok']];
        $body = $factory->build($model, ['input' => $input], $options);
        $this->assertSame($input, $body['input']);

        foreach ([[], [['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']]]] as $seed) {
            $this->assertSame(
                [],
                $factory->build(
                    $model,
                    ['input' => $seed],
                    $options + [CodexRequestBodyFactory::REASONING_UPDATE => 'high'],
                )['input'],
            );
        }

        $first = $factory->build($model, ['input' => [['role' => 'user', 'content' => 'first']]], $options);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($first, 'resp-1', []);
        $repeated = $factory->build($model, ['input' => $first['input']], $options);
        $this->assertSame(['previous_response_id' => 'resp-1', 'input' => []], $state->buildDeltaRequest($repeated));
    }

    public function testReturnToBaselineKeepsEarlierTransitionsStable(): void
    {
        $ledger = new CodexReasoningTransitionLedger();
        $factory = new CodexRequestBodyFactory($ledger);
        $model = new CodexModel('gpt-6-astra');
        $cacheKey = 'cache-family-3';
        $base = [
            'prompt_cache_key' => $cacheKey,
            'reasoning' => ['effort' => 'medium'],
        ];

        $afterHigh = $factory->build(
            $model,
            ['input' => [
                ['role' => 'user', 'content' => 'u1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['role' => 'user', 'content' => 'u2'],
            ]],
            $base + [CodexRequestBodyFactory::REASONING_UPDATE => 'high'],
        );
        $this->assertSame(
            [
                ['role' => 'user', 'content' => 'u1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'u2'],
            ],
            $afterHigh['input'],
        );

        $return = $factory->build(
            $model,
            ['input' => [
                ['role' => 'user', 'content' => 'u1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['role' => 'user', 'content' => 'u2'],
                ['role' => 'assistant', 'content' => 'a2'],
                ['role' => 'user', 'content' => 'u3'],
            ]],
            $base + [CodexRequestBodyFactory::REASONING_UPDATE => 'medium'],
        );
        $this->assertSame(
            [
                ['role' => 'user', 'content' => 'u1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'u2'],
                ['role' => 'assistant', 'content' => 'a2'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'medium']],
                ['role' => 'user', 'content' => 'u3'],
            ],
            $return['input'],
        );
    }

    public function testNonAstraShapingIsUnchanged(): void
    {
        $factory = new CodexRequestBodyFactory(new CodexReasoningTransitionLedger());
        $input = [['role' => 'user', 'content' => 'hello']];
        $body = $factory->build(new CodexModel('gpt-5.6-luna'), ['input' => $input], ['reasoning' => ['effort' => 'high'], CodexRequestBodyFactory::REASONING_UPDATE => 'low']);
        $this->assertSame($input, $body['input']);
        $this->assertSame('high', $body['reasoning']['effort']);
        $this->assertArrayNotHasKey(CodexRequestBodyFactory::REASONING_UPDATE, $body);
    }
}
