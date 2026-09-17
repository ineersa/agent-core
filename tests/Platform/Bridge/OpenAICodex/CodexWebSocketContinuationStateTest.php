<?php

declare(strict_types=1);

namespace Symfony\AI\Platform\Bridge\OpenAICodex\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Bridge\OpenAICodex\CodexWebSocketContinuationState;

final class CodexWebSocketContinuationStateTest extends TestCase
{
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

    public function testHistoricalConfigurationUpdatesRemainInPrefixAndOnlyNewSuffixIsDelta(): void
    {
        $baselineBody = [
            'model' => 'gpt-6-astra',
            'input' => [
                ['role' => 'user', 'content' => 'u1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'u2'],
            ],
            'stream' => true,
        ];
        $state = CodexWebSocketContinuationState::fromSuccessfulResponse(
            $baselineBody,
            'resp_astra',
            [['type' => 'message', 'role' => 'assistant', 'content' => 'a2']],
        );

        $current = [
            'model' => 'gpt-6-astra',
            'input' => [
                ['role' => 'user', 'content' => 'u1'],
                ['role' => 'assistant', 'content' => 'a1'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['role' => 'user', 'content' => 'u2'],
                ['type' => 'message', 'role' => 'assistant', 'content' => 'a2'],
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
                ['role' => 'user', 'content' => 'u3'],
            ],
            'stream' => true,
        ];

        $delta = $state->buildDeltaRequest($current);
        $this->assertNotNull($delta);
        $this->assertSame('resp_astra', $delta['previous_response_id']);
        $this->assertSame([
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']],
            ['role' => 'user', 'content' => 'u3'],
        ], $delta['input']);
    }
}
