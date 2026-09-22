<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexModel;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;
use Symfony\AI\Platform\Bridge\OpenAICodex\Contract\CodexContract;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;

final class CodexWebSocketContinuationStateTest extends TestCase
{
    public function testNormalizedAssistantHistoryContinuesNativeResponseWithoutReplayingIt(): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-6-astra');
        $user = Message::ofUser('first');
        $body = $contract->createRequestPayload($model, new MessageBag($user), []);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($body, 'resp_text', [[
            'id' => 'msg_native',
            'type' => 'message',
            'status' => 'completed',
            'role' => 'assistant',
            'phase' => 'final_answer',
            'content' => [['type' => 'output_text', 'annotations' => [], 'logprobs' => [], 'text' => 'answer']],
        ]]);
        $current = $contract->createRequestPayload($model, new MessageBag($user, Message::ofAssistant('answer'), Message::ofUser('next')), []);

        $delta = $state->buildDeltaRequest($current);
        $this->assertNotNull($delta);
        $this->assertSame('resp_text', $delta['previous_response_id']);
        $this->assertSame([$current['input'][2]], $delta['input']);

        $current['input'][1]['content'][0]['text'] = 'edited answer';
        $this->assertNull($state->buildDeltaRequest($current), 'Changed assistant content must still reject continuation.');
    }

    public function testNormalizedToolHistoryContinuesNativeResponseWithoutReplayingCall(): void
    {
        $contract = CodexContract::create();
        $model = new CodexModel('gpt-6-astra');
        $user = Message::ofUser('read fixture');
        $body = $contract->createRequestPayload($model, new MessageBag($user), []);
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse($body, 'resp_tool', [[
            'id' => 'fc_native',
            'type' => 'function_call',
            'status' => 'completed',
            'call_id' => 'call_native',
            'name' => 'read',
            'arguments' => '{ "path": "./probe.txt" }',
        ]]);
        $call = new ToolCall('call_native|fc_native', 'read', ['path' => './probe.txt']);
        $current = $contract->createRequestPayload($model, new MessageBag(
            $user,
            Message::ofAssistant(new ToolCallResult([$call])),
            Message::ofToolCall($call, 'fixture'),
        ), []);

        $delta = $state->buildDeltaRequest($current);
        $this->assertNotNull($delta);
        $this->assertSame('resp_tool', $delta['previous_response_id']);
        $this->assertSame([$current['input'][2]], $delta['input']);

        $changedCall = $current;
        $changedCall['input'][1]['call_id'] = 'call_other';
        $this->assertNull($state->buildDeltaRequest($changedCall), 'A different call must not inherit the response.');
        $reordered = $current;
        [$reordered['input'][0], $reordered['input'][1]] = [$reordered['input'][1], $reordered['input'][0]];
        $this->assertNull($state->buildDeltaRequest($reordered), 'Object key order is irrelevant, but input item order is not.');

        $current['input'][1]['arguments'] = '{"path":"./different.txt"}';
        $this->assertNull($state->buildDeltaRequest($current), 'Changed tool arguments must still reject continuation.');
    }

    public function testBuildsDeltaForStrictExtension(): void
    {
        $baselineBody = [
            'model' => 'gpt-5.6-luna',
            'input' => [['role' => 'user', 'content' => 'first']],
            'stream' => true,
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $baselineBody,
            'resp_123',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
        );

        $current = [
            'model' => 'gpt-5.6-luna',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['role' => 'user', 'content' => 'second'],
            ],
            'stream' => true,
        ];

        $delta = $state->buildDeltaRequest($current);
        $this->assertNotNull($delta);
        $this->assertSame('resp_123', $delta['previous_response_id']);
        $this->assertCount(1, $delta['input']);
        $this->assertSame('second', $delta['input'][0]['content']);
    }

    public function testDivergentBodyReturnsNull(): void
    {
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            ['model' => 'gpt-5.6-luna', 'input' => [], 'stream' => true],
            'resp_123',
            [],
        );

        $delta = $state->buildDeltaRequest([
            'model' => 'gpt-5.6-sol',
            'input' => [['role' => 'user', 'content' => 'x']],
            'stream' => true,
        ]);

        $this->assertNull($delta);
    }

    public function testKeepsHistoricalConfigurationUpdateInPrefixAndDeltasOnlySuffix(): void
    {
        $baselineBody = [
            'model' => 'gpt-6-astra',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'second'],
            ],
            'stream' => true,
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $baselineBody,
            'resp_123',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'ok']],
        );

        $current = [
            'model' => 'gpt-6-astra',
            'input' => [
                ['role' => 'user', 'content' => 'first'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'second'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'ok'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
                ['role' => 'user', 'content' => 'third'],
            ],
            'stream' => true,
        ];

        $delta = $state->buildDeltaRequest($current);
        $this->assertNotNull($delta);
        $this->assertSame('resp_123', $delta['previous_response_id']);
        $this->assertSame([
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
            ['role' => 'user', 'content' => 'third'],
        ], $delta['input']);
    }
}
