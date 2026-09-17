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
}
